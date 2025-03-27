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
   * Whether to return raw classification results.
   *
   * If null, use the value from the configuration. If set, override the
   * configuration.
   *
   * @var bool
   */
  private $raw = null;

  /**
   * Parameters for the classifier.
   *
   * @var bool
   */
  private $params = null;

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
    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Service options'),
      '#open' => FALSE,
      'raw' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Include full output in response'),
        '#default_value' => $this->configuration['options']['raw'] ?? FALSE,
        '#description' => $this->t('If enabled, response will include the raw
        output from the classifier.'),
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
    unset($options['body']);

    // If settings are included in the post data then save for later.
    if (isset($postargs['raw'])) {
      $this->raw = $postargs['raw'];
    }

    // Handle images.
    // Note our parameter is called 'image' but PlantNet uses 'images'.
    foreach ($postargs['image'] as $image) {
      $options['multipart'][] = [
        'name' => 'images',
        'contents' => Utils::tryFopen($image, 'r'),
      ];
    }

    // Add parameters to the request.
    if (isset($postargs['params'])) {
      $params = json_decode($postargs['params'], TRUE);
      if ($params === NULL) {
        throw new \InvalidArgumentException('The params setting must be a
        valid JSON object.');
      }

      if (isset($params['form'])) {
        // Add form params to the multipart form.
        foreach ($params['form'] as $name => $contents) {
          if (is_array($contents)) {
            // Convert array parameters in to separate multipart elements with
            // the same name.
            foreach ($contents as $value) {
              $options['multipart'][] = [
                'name' => $name,
                'contents' => $value,
              ];
            }
          }
          else {
            $options['multipart'][] = [
              'name' => $name,
              'contents' => $contents,
            ];
          }
        }
      }

      // Add query params to the query (not overwriting api-key which was
      // added in preprocessIncoming()). Convert booleans to srings that
      // PlantNet accepts. "
      if (isset($params['query'])) {
        foreach ($params['query'] as $name => $value) {
          // Convert booleans to strings. Standard coercion results in '1'/''.
          if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
          }
          $options['query'][$name] = $value;
        }
      }
      // Save to include in response.
      $this->params = $postargs['params'];
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

    if (isset($this->params)) {
      $data['params'] = $this->params;
    }

    $data['suggestions'] = [];
    foreach ($classification['results'] as $prediction) {
      // Add prediction to results.
      $data['suggestions'][] = [
        'probability' => $prediction['score'],
        'taxon' => $prediction['species']['scientificNameWithoutAuthor'],
      ];
    }

    // Optionally append raw classifier output.
    if (isset($this->raw)) {
      // Parameter submitted in request takes precedence.
      $raw = $this->raw;
    }
    else {
      // Otherwise use value from configuration.
      $raw = $this->configuration['options']['raw'];
    }
    if (is_string($raw)) {
      $raw = strtolower($raw);
    }
    $truthy = ['1', 'yes', 'true', TRUE, 1];
    if (in_array($raw, $truthy, TRUE)) {
      $data['raw'] = $classification;
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
