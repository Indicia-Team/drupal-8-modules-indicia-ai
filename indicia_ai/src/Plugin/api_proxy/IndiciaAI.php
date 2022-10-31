<?php

namespace Drupal\indicia_ai\Plugin\api_proxy;

use Drupal\api_proxy\Plugin\api_proxy\HttpApiCommonConfigs;
use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\Core\Form\SubformStateInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use GuzzleHttp\Psr7\Utils;

iform_load_helpers(['data_entry_helper']);

/**
 * Proxy fronting all image classifiers.
 *
 * @HttpApi(
 *   id = "indicia",
 *   label = @Translation("Indicia AI"),
 *   description = @Translation("Proxies requests to AI services."),
 *   serviceUrl = "https://localhost",
 * )
 */
final class IndiciaAI extends HttpApiPluginBase {

  use HttpApiCommonConfigs;

  /**
   * Array of species groups to constrain output to.
   *
   * IDs from the taxon_groups table of your warehouse.
   *
   * @var int[]
   */
  private $taxonGroupIds;

  /**
   * ID of taxon list used to look up species on your warehouse.
   *
   * @var int
   */
  private $taxonListId;


  /**
   * {@inheritdoc}
   */
  public function addMoreConfigurationFormElements(array $form, SubformStateInterface $form_state): array {
    $form['classify'] = [
      '#type' => 'details',
      '#title' => $this->t('Classification'),
      '#open' => FALSE,
      'threshold' => [
        '#type' => 'textfield',
        '#title' => $this->t('Probability threshold'),
        '#default_value' => $this->configuration['classify']['threshold'] ?? 0.5,
        '#required' => TRUE,
        '#description' => $this->t('Threshold of classification probability,
        below which responses are ignored (0.0 to 1.0).'),
      ],
      'suggestions' => [
        '#type' => 'textfield',
        '#title' => $this->t('Maximum number of suggestions'),
        '#default_value' => $this->configuration['classify']['suggestions'] ?? 1,
        '#description' => $this->t('The maximum number of classification
        suggestions to be returned. Note, the number of suggestions also depends
        on the probability threshold. If the threshold is >= 0.5, there can only
        be one suggestion as the sum of probabilities is 1.0.'),
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
    return $headers;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIncoming(string $method, string $uri, HeaderBag $headers, ParameterBag $query): array {
    // Replace the serviceUrl provided by the annotation with the actual host.
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $path = str_replace($this->getBaseUrl(), '', $uri);

    if ($path == '/') {
      // We need to pick a classifier to use as it wasn't supplied.
      $path = '/nia';
    }

    $uri = "$host/api-proxy$path";
    $query->add(['_api_proxy_uri' => 'dummy']);
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

    // If settings are included in the post data then save for later.
    if (isset($postargs['list'])) {
      $this->taxonListId = $postargs['list'];
      unset($postargs['list']);
    }
    if (isset($postargs['groups'])) {
      $this->taxonGroupIds = $postargs['groups'];
      unset($postargs['groups']);
    }

    // Get path to image file.
    if (isset($postargs['image'])) {
      $image_path = $postargs['image'];
      if (substr($image_path, 0, 4) == 'http') {
        // The image has to be obtained from a url.
        // Do a head request to determine the content-type.
        $handle = curl_init($image_path);
        curl_setopt($handle, CURLOPT_NOBODY, TRUE);
        curl_exec($handle);
        $content_type = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);

        // Open an interim file.
        $download_path = \data_entry_helper::getInterimImageFolder('fullpath');
        $download_path .= uniqid('indicia_ai_');
        switch ($content_type) {
          case 'image/png':
            $download_path .= '.png';
            break;

          case 'image/jpeg':
            $download_path .= '.jpg';
            break;

          default:
            throw new \InvalidArgumentException("Unhandled content type: $content_type.");
        }

        // Download image to interim file.
        $fp = fopen($download_path, 'w+');
        $handle = curl_init($image_path);
        curl_setopt($handle, CURLOPT_TIMEOUT, 50);
        curl_setopt($handle, CURLOPT_FILE, $fp);
        curl_exec($handle);
        curl_close($handle);
        fclose($fp);
        $image_path = $download_path;
      }
      else {
        // The image is stored locally
        // Determine full path to local file.
        $image_path =
          \data_entry_helper::getInterimImageFolder('fullpath') . $image_path;
      }

      $postargs['image'] = $image_path;
    }
    else {
      throw new \InvalidArgumentException('The POST body must contain an image
      parameter holding the location of the image to classify.');
    }

    $options['body'] = http_build_query($postargs);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessOutgoing(Response $response): Response {
    // Modify the response from the API.

    $classification = json_decode($response->getContent(), TRUE);

    $connection = iform_get_connection_details(null);
    $readAuth = \data_entry_helper::get_read_auth(
      $connection['website_id'], $connection['password']
    );

    $data = [];
    foreach ($classification['suggestions'] as $suggestion) {
      // Find predictions above the threshold.
      if ($suggestion['probability'] >= $this->configuration['classify']['threshold']) {

        $warehouse_data = [];
        if (!empty($this->taxonListId)) {
          // Perform lookup in Indicia species list.
          $getargs = [
            'searchQuery' => $suggestion['taxon'],
            'taxon_list_id' => $this->taxonListId,
            'language' => 'lat',
          ] + $readAuth;
          $url = $connection['base_url'] . 'index.php/services/data/taxa_search?';
          $url .= http_build_query($getargs);
          $session = curl_init($url);
          curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
          $taxa_search = curl_exec($session);
          if ($taxa_search !== FALSE) {
            // Request was successful.
            $taxa = json_decode($taxa_search, TRUE);
            if (count($taxa) > 0) {
              // Results are returned in priority order. Going to assume the
              // first is the correct match for now.
              $warehouse_data = [
                'taxon' => $taxa[0]['preferred_taxon'],
                'taxa_taxon_list_id' => $taxa[0]['preferred_taxa_taxon_list_id'],
                'taxon_group_id' => $taxa[0]['taxon_group_id'],
              ];
            }
          }

          if (!empty($this->taxonGroupIds)) {
            // Exclude predictions not in selected groups.
            if (!in_array($warehouse_data['taxon_group_id'], $this->taxonGroupIds)) {
              // Skip to next prediction.
              continue;
            }
          }

          // Add prediction to results.
          $data[] = [
            'probability' => $suggestion['probability'],
          ] + $warehouse_data;
        }
        else {
          // Just pass through classifier results.
          $data[] = $suggestion;
        }

        // Exit loop if we have got enough suggestions.
        if (count($data) == $this->configuration['classify']['suggestions']) {
          break;
        }
      }
    }

    // Update response with filtered results.
    $classification['suggestions'] = $data;
    $response->setContent(json_encode($classification));
    return $response;
  }

}
