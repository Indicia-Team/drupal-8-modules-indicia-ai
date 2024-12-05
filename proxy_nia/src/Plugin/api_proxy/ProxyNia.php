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
 *   serviceUrl = "https://waarneming.nl/api",
 * )
 */
final class ProxyNia extends HttpApiPluginBase {

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
      'client_id' => [
        '#type' => 'textfield',
        '#title' => $this->t('Client ID'),
        '#default_value' => $this->configuration['auth']['client_id'] ?? '',
        '#description' => $this->t('An ID provided by the API administrators
        granting access.'),
      ],
      'email' => [
        '#type' => 'textfield',
        '#title' => $this->t('Email'),
        '#default_value' => $this->configuration['auth']['email'] ?? '',
        '#description' => $this->t('Email address of an account on
        https://observation.org used for authentication.'),
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
      '#description' => 'The full path to the service is calculated as ' .
      rtrim($this->getBaseUrl(), '/') .
      '/{service}/{version}/?app_name={app name}',
      'service' => [
        '#type' => 'textfield',
        '#title' => $this->t('Service'),
        '#default_value' => $this->configuration['path']['service'] ?? '',
        '#description' => $this->t('The name of the service to call.
        E.g. identify-proxy'),
      ],
      'version' => [
        '#type' => 'textfield',
        '#title' => $this->t('Version'),
        '#default_value' => $this->configuration['path']['version'] ?? '',
        '#description' => $this->t('The version of the service to call.
        E.g. v1'),
      ],
      'app_name' => [
        '#type' => 'textfield',
        '#title' => $this->t('App name'),
        '#default_value' => $this->configuration['path']['app_name']  ?? '',
        '#description' => $this->t('The app_name parameter required by the
        endpoint. E.g. uni-jena'),
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
    // Remove origin otherwise we get a 404 response (possibly because CORS is
    // not supported).
    $headers = Utils::caselessRemove(
      ['Content-Type', 'content-length', 'origin'], $headers
    );

    // Request an auth token.
    $handle = curl_init('https://waarneming.nl/api/v1/oauth2/token/');
    curl_setopt($handle, CURLOPT_POSTFIELDS, [
      'client_id' => $this->configuration['auth']['client_id'],
      'grant_type' => 'password',
      'email' => $this->configuration['auth']['email'],
      'password' => $this->configuration['auth']['password'],
    ]);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
    $tokens = curl_exec($handle);
    if ($tokens !== FALSE) {
      $tokens = json_decode($tokens, TRUE);
      $headers['authorization'] = ['Bearer ' . $tokens['access_token']];
    }
    curl_close($handle);

    // @todo cache tokens for their lifetime.
    return $headers;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIncoming(string $method, string $uri, HeaderBag $headers, ParameterBag $query): array {
    // Construct service call from configuration, overriding anything passed in
    // by the _api_proxy_uri parameter.
    $path = rtrim($this->getBaseUrl(), '/');
    $service = trim($this->configuration['path']['service'], '/');
    $version = trim($this->configuration['path']['version'], '/');
    $uri = "$path/$service/$version/";

    $query->add(['app_name' => $this->configuration['path']['app_name']]);
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

    // We have to post the image file content to waarneming as
    // multipart/form-data.
    if (isset($postargs['image'])) {
      $image_path = $postargs['image'];

      // Replace the body option with a multipart option.
      $contents = fopen($image_path, 'r');
      if (!$contents) {
        throw new \InvalidArgumentException('The image could not be opened.');
      }
      $options['multipart'] = [
        [
          'name' => 'image',
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
    $data['classifier_version'] = $this->configuration['path']['version'];
    $data['suggestions'] = [];
    foreach ($classification['predictions'] as $prediction) {
      // Add prediction to results.
      $data['suggestions'][] = [
        'probability' => $prediction['probability'],
        'taxon' => $prediction['taxon']['name'],
      ];
    }
    // Update response.
    $response->setContent(json_encode($data));
    return $response;
  }

}
