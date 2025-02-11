<?php

namespace Drupal\proxy_nia\Plugin\api_proxy;

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
 *   id = "nia",
 *   label = @Translation("Nature Identification API"),
 *   description = @Translation("Proxies requests to the image classifier."),
 *   serviceUrl = "https://multi-source.identify.biodiversityanalysis.eu",
 * )
 */
final class ProxyNia extends HttpApiPluginBase {

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
      'username' => [
        '#type' => 'textfield',
        '#title' => $this->t('Username'),
        '#default_value' => $this->configuration['auth']['username'] ?? '',
        '#description' => $this->t('A username provided by the API
        administrators granting access.'),
      ],
      'password' => [
        '#type' => 'textfield',
        '#title' => $this->t('Password'),
        '#default_value' => $this->configuration['auth']['password'] ?? '',
        '#description' => $this->t('Password of account used for authentication.'),
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
      '#description' => $this->t(
        'The full path to the service is calculated as
        @basepath/{version}/{service}/{token}',
        ['@basepath' => rtrim($this->getBaseUrl(), '/')]
      ),
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
        E.g. observation/identify/token'),
      ],
      'token' => [
        '#type' => 'textfield',
        '#title' => $this->t('Token'),
        '#default_value' => $this->configuration['path']['token'] ?? '',
        '#description' => $this->t('Token used for authorisation to give
        results tuned to a region.'),
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
    // Remove content-type and content-length to ensure it is set correctly for
    // the post we will make rather than the one we received.
    // Remove origin.
    $headers = Utils::caselessRemove(
      ['Content-Type', 'content-length', 'origin'], $headers
    );

    // Add basic authorization header
    $username = $this->configuration['auth']['username'];
    $password = $this->configuration['auth']['password'];
    $auth = base64_encode("$username:$password");
    $headers['authorization'] = ['Basic ' . $auth];

    return $headers;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIncoming(string $method, string $uri, HeaderBag $headers, ParameterBag $query): array {
    // Construct service call from configuration, overriding anything passed in
    // by the _api_proxy_uri parameter.
    $base = rtrim($this->getBaseUrl(), ' /');
    $version = trim($this->configuration['path']['version'], ' /');
    $service = trim($this->configuration['path']['service'], ' /');
    $token = trim($this->configuration['path']['token'], ' /');
    $uri = "$base/$version/$service/$token";

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
      unset($postargs['raw']);
    }

    // We have to post the image file content as multipart/form-data.
    if (!isset($postargs['image'])) {
      throw new \InvalidArgumentException('The POST body must contain an image
      parameter holding the location of the image(s) to classify.');
    }

    if (is_array($postargs['image'])) {
      // Multiple images.
      foreach ($postargs['image'] as $image_path) {
        $options['multipart'][] = [
          'name' => 'image',
          'contents' => Utils::tryFopen($image_path, 'r'),
        ];
      }
    }
    else {
      // Single image.
      $image_path = $postargs['image'];
      $options['multipart'][] = [
        'name' => 'image',
        'contents' => Utils::tryFopen($image_path, 'r'),
      ];
    }
    unset ($postargs['image']);

    // Add parameters to the request.
    if (isset($postargs['params'])) {
      $params = json_decode($postargs['params'], TRUE);
      if ($params === NULL) {
        throw new \InvalidArgumentException('The params setting must be a
        valid JSON object.');
      }
      // Add form params to the multipart form.
      if (isset($params['form'])) {
        foreach ($params['form'] as $name => $value) {
          $options['multipart'][] = [
            'name' => $name,
            'contents' => $value,
          ];
        }
      }
      // Save to include in response.
      $this->params = $postargs['params'];
      unset($postargs['params']);
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
    $data['classifier_version'] = $classification['generated_by']['tag'];

    if (isset($this->params)) {
      $data['params'] = $this->params;
    }

    $data['suggestions'] = [];
    foreach ($classification['predictions'][0]['taxa']['items'] as $prediction) {
      // Add prediction to results.
      $data['suggestions'][] = [
        'probability' => $prediction['probability'],
        'taxon' => $prediction['scientific_name'],
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

}
