<?php

namespace Drupal\proxy_plantnet\Plugin\api_proxy;

use Drupal\api_proxy\Plugin\api_proxy\HttpApiCommonConfigs;
use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\Core\Form\SubformStateInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use GuzzleHttp\Psr7\Utils;

iform_load_helpers(['data_entry_helper']);

/**
 * The Example API.
 *
 * @HttpApi(
 *   id = "plantnet",
 *   label = @Translation("PlantNet API"),
 *   description = @Translation("Proxies requests to the visual identification engine."),
 *   serviceUrl = "https://my-api.plantnet.org",
 * )
 */
final class ProxyPlantNet extends HttpApiPluginBase {

  use HttpApiCommonConfigs;

  /**
   * Array of species groups to constrain output to.
   *
   * @var int[]
   */
  private $groups;

  /**
   * {@inheritdoc}
   */
  public function addMoreConfigurationFormElements(array $form, SubformStateInterface $form_state): array {
    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('Authentication'),
      '#open' => FALSE,
      'api_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('API Key'),
        '#default_value' => $this->configuration['auth']['api_key'] ?? '',
        '#description' => $this->t('A key provided by the API administrators
        granting access.'),
      ],
      'id' => [
        '#type' => 'textfield',
        '#title' => $this->t('Classifier ID'),
        '#default_value' => $this->configuration['auth']['id'] ?? '',
        '#description' => $this->t('ID of classifier from the term list of
        media classifiers on your warehouse.'),
      ],
    ];
    $form['path'] = [
      '#type' => 'details',
      '#title' => $this->t('Service path'),
      '#open' => FALSE,
      '#description' => 'The full path to the service is calculated as ' .
      rtrim($this->getBaseUrl(), '/') .
      '/{version}/{service}/{project}',
      'version' => [
        '#type' => 'textfield',
        '#title' => $this->t('Version'),
        '#default_value' => $this->configuration['path']['version'] ?? '',
        '#description' => $this->t('The version of the service to call.
        E.g. v2'),
      ],
      'service' => [
        '#type' => 'textfield',
        '#title' => $this->t('Service'),
        '#default_value' => $this->configuration['path']['service'] ?? '',
        '#description' => $this->t('The name of the service to call.
        E.g. identify'),
      ],
      'project' => [
        '#type' => 'textfield',
        '#title' => $this->t('Project'),
        '#default_value' => $this->configuration['path']['project'] ?? '',
        '#description' => $this->t('Choose specific floras, e.g. "weurope",
        "canada", or choose "all"'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function calculateHeaders(array $headers): array {
    // Modify & add new headers.
    // Call the parent function to apply settings from the config page.
    $headers = parent::calculateHeaders($headers);
    // Remove content-type, content-length, and authorization to ensure they are
    // set correctly for the post we will make rather than the one we received.
    // Remove origin otherwise we get a 404 response (possibly because CORS is
    // not supported).
    $headers = Utils::caselessRemove(
      ['Content-Type', 'content-length', 'authorization', 'origin'], $headers
    );

    return $headers;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIncoming(string $method, string $uri, HeaderBag $headers, ParameterBag $query): array {
    // Construct service call from configuration, overriding anything passed in
    // by the _api_proxy_uri parameter.
    $path = rtrim($this->getBaseUrl(), '/');
    $version = trim($this->configuration['path']['version'], '/');
    $service = trim($this->configuration['path']['service'], '/');
    $project = trim($this->configuration['path']['project'], '/');
    $uri = "$path/$version/$service/$project";

    $query->add(['api-key' => $this->configuration['auth']['api_key']]);
    return [$method, $uri, $headers, $query];
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessOutgoingRequestOptions(array $options): array {
    $postargs = [];

    // api_proxy module just handles POST data as a single body item.
    // https://docs.guzzlephp.org/en/6.5/request-options.html#body
    parse_str($options['body'], $postargs);

    // We have to post the image file content to plantnet as
    // multipart/form-data.
    if (isset($postargs['image'])) {
      $image_path = $postargs['image'];

      // Replace the body option with a multipart option.
      $contents = fopen($image_path, 'r');
      if (!$contents) {
        throw new \InvalidArgumentException('The image could not be opened.');
      }
      // At present we are sending a single image and allowing the `organ`
      // parameter to default to `auto`.
      $options['multipart'] = [
        [
          'name' => 'images',
          'contents' => $contents,
        ],
      ];
      unset($options['body']);
    }
    else {
      throw new \InvalidArgumentException('The POST body must contain an image
      parameter holding the location of the image to classify.');
    }

    // Fix problem where $options['version'] is like HTTP/x.y, as set in
    // $_SERVER['SERVER_PROTOCOL'], but Guzzle expects just x.y.
    // PHP docs https://www.php.net/manual/en/reserved.variables.server.php
    // Guzzle https://docs.guzzlephp.org/en/stable/request-options.html#version
    // This problem only became evident with extra error checking added in
    // Guzzle 7.9 to which we upgraded on 23/7/2024.
    // I have raised https://www.drupal.org/project/api_proxy/issues/3463730
    // When it is fixed we can remove this code.
    if (
      isset($options['version']) &&
      substr($options['version'], 0, 5) == 'HTTP/'
    )  {
      $options['version'] = substr($options['version'], 5);
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessOutgoing(Response $response): Response {
    // Modify the response from the API.

    $classification = json_decode($response->getContent(), TRUE);

    $data['classifier_id'] = $this->configuration['auth']['id'];
    $data['classifier_version'] = $classification['version'];
    $data['suggestions'] = [];
    foreach ($classification['results'] as $prediction) {
      // Add prediction to results.
      $data['suggestions'][] = [
        'probability' => $prediction['score'],
        'taxon' => $prediction['species']['scientificNameWithoutAuthor'],
      ];
    }
    // Update response.
    $response->setContent(json_encode($data));
    return $response;
  }

  // /**
  //  * Make PHP / CURL compliant with multidimensional arrays.
  //  *
  //  * @var ch
  //  * @var postfields
  //  * @var headers
  //  *
  //  * @return
  //  */
  // function curl_setopt_custom_postfields($ch, $postfields, $headers = NULL) {
  //   // Choose a hashing algorithm.
  //   $algos = hash_algos();
  //   $hashAlgo = NULL;

  //   foreach (['sha1', 'md5'] as $preferred) {
  //     if (in_array($preferred, $algos)) {
  //       $hashAlgo = $preferred;
  //       break;
  //     }
  //   }

  //   if ($hashAlgo === NULL) {
  //     list($hashAlgo) = $algos;
  //   }

  //   $boundary = '----------------------------' . substr(
  //     hash($hashAlgo, 'cURL-php-multiple-value-same-key-support' . microtime()),
  //     0,
  //     12
  //   );

  //   $body = [];
  //   $crlf = "\r\n";
  //   $fields = [];

  //   // Flatten a postfield with array value in to multiple fields.
  //   foreach ($postfields as $key => $value) {
  //     if (is_array($value)) {
  //       foreach ($value as $v) {
  //         $fields[] = [$key, $v];
  //       }
  //     }
  //     else {
  //       $fields[] = [$key, $value];
  //     }
  //   }

  //   foreach ($fields as $field) {
  //     list($key, $value) = $field;

  //     if (strpos($value, '@') === 0) {
  //       preg_match('/^@(.*?)$/', $value, $matches);
  //       list($dummy, $filename) = $matches;

  //       $body[] = '--' . $boundary;
  //       $body[] = 'Content-Disposition: form-data; name="' . $key . '"; filename="' . basename($filename) . '"';
  //       $body[] = 'Content-Type: application/octet-stream';
  //       $body[] = '';
  //       $body[] = file_get_contents($filename);
  //     }
  //     else {
  //       $body[] = '--' . $boundary;
  //       $body[] = 'Content-Disposition: form-data; name="' . $key . '"';
  //       $body[] = '';
  //       $body[] = $value;
  //     }
  //   }

  //   $body[] = '--' . $boundary . '--';
  //   $body[] = '';

  //   $contentType = 'multipart/form-data; boundary=' . $boundary;
  //   $content = join($crlf, $body);

  //   $contentLength = strlen($content);

  //   curl_setopt($ch, CURLOPT_HTTPHEADER, [
  //     'Content-Length: ' . $contentLength,
  //     'Expect: 100-continue',
  //     'Content-Type: ' . $contentType,
  //   ]);

  //   curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
  // }

  // $PROJECT = "all"; // try specific floras: "weurope", "canada"…

  // $url = 'https://my-api.plantnet.org/v2/identify/' . $PROJECT . '?api-key=YOUR-PRIVATE-API-KEY-HERE';

  // $data = array(
  // 'organs' => array(
  // 'flower',
  // 'leaf',
  // ),
  // 'images' => array(
  // '@/data/media/image_1.jpeg',
  // '@/data/media/image_2.jpeg'
  // )
  // );

  // $ch = curl_init(); // init cURL session

  // curl_setopt($ch, CURLOPT_URL, $url); // set the required URL
  // curl_setopt($ch, CURLOPT_POST, true); // set the HTTP method to POST
  // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // get a response, rather than print it
  // curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false); // allow "@" files management
  // curl_setopt_custom_postfields($ch, $data); // set the multidimensional array param
  // $response = curl_exec($ch); // execute the cURL session

  // curl_close($ch); // close the cURL session

  // echo $response;



}
