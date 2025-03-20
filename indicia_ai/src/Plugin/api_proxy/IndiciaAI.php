<?php

namespace Drupal\indicia_ai\Plugin\api_proxy;

use Drupal\api_proxy\Plugin\api_proxy\HttpApiCommonConfigs;
use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Form\FormStateInterface;
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
  use LoggerChannelTrait;

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
   * List of rules used to verify with Record Cleaner.
   *
   * @var float
   */
  private $orgGroupRulesList = [];

  /**
   * Spatial reference of observation used to verify with Record Cleaner.
   *
   * @var float
   */
  private $sref;

  /**
   * Date of observation used to verify with Record Cleaner.
   *
   * @var string
   */
  private $date;

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

    $form['cleaner'] = [
      '#type' => 'details',
      '#title' => $this->t('Record Cleaner'),
      '#open' => FALSE,
      'enable' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Record Cleaner Checks'),
        '#default_value' => $this->configuration['cleaner']['enable'] ?? FALSE,
        '#description' => $this->t('If enabled, suggestions will be annotated
        with Record Cleaner verification status.'),
      ],
      'url' => [
        '#type' => 'textfield',
        '#title' => $this->t('Url'),
        '#default_value' => ($this->configuration['cleaner']['url'] ??
          'https://record-cleaner.brc.ac.uk'),
        '#states' => [
          'visible' => [
            ':input[name="indicia[cleaner][enable]"]' => ['checked' => TRUE],
          ],
          'required' => [
            ':input[name="indicia[cleaner][enable]"]' => ['checked' => TRUE],
          ],
        ],
        '#description' => $this->t('Url of Record Cleaner service. (No trailing
        slash.)' ),
      ],
      'username' => [
        '#type' => 'textfield',
        '#title' => $this->t('Username'),
        '#default_value' => $this->configuration['cleaner']['username'] ?? '',
        '#states' => [
          'visible' => [
            ':input[name="indicia[cleaner][enable]"]' => ['checked' => TRUE],
          ],
          'required' => [
            ':input[name="indicia[cleaner][enable]"]' => ['checked' => TRUE],
          ],
        ],
        '#description' => $this->t('Username for authenticating with Record
        Cleaner service.'),
      ],
      'password' => [
        '#type' => 'password',
        '#title' => $this->t('Password'),
        '#default_value' => $this->configuration['cleaner']['password'] ?? '',
        '#states' => [
          'visible' => [
            ':input[name="indicia[cleaner][enable]"]' => ['checked' => TRUE],
          ],
        ],
        '#description' => $this->t('Password for authenticating with Record
        Cleaner service. You only need to enter a value to chnage it.'),
      ],
    ];


    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $enabled =  $form_state->getValue(['cleaner', 'enable']);
    $password = $form_state->getValue(['cleaner', 'password']);
    $currentPassword = $this->configuration['cleaner']['password'] ?? NULL;

    if ($enabled && $password == '' && $currentPassword == NULL) {
      $form_state->setErrorByName('cleaner][password', $this->t("A password
        is needed to use Record Cleaner checks."));
    }
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $password = $form_state->getValue(['cleaner', 'password']);
    // If password is not entered, leave it unchanged.
    if ($password == '') {
      $currentPassword = $this->configuration['cleaner']['password'];
      $form_state->setValue(['cleaner', 'password'], $currentPassword);
    }
    parent::submitConfigurationForm($form, $form_state);
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
    // This is a bit of a cludge because I am using one api_proxy to call
    // another. I have to recalculate $uri and also add an _api_proxy_uri
    // parameter which then goes unused.
    $host = \Drupal::request()->getSchemeAndHttpHost();
    // Extract any classifier requested.
    $path = str_replace($this->getBaseUrl(), '', $uri);

    if ($path == '/') {
      // We need to pick a classifier to use as it wasn't supplied.
      // @todo This should be added to the module configuration options.
      $path = '/nia';
    }

    // Calculate the uri of the api_proxy to call next.
    $uri = "$host/api-proxy$path";
    // All api_proxy calls require an _api_proxy_uri parameter but each
    // classifier module will have to calculate its own value.
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
    if (isset($postargs['org_group_rules_list'])) {
      $this->orgGroupRulesList = json_decode($postargs['org_group_rules_list'], TRUE);
      unset($postargs['org_group_rules_list']);
    }
    if (isset($postargs['sref'])) {
      $this->sref = json_decode($postargs['sref'], TRUE);
      unset($postargs['sref']);
    }
    if (isset($postargs['date'])) {
      $this->date = $postargs['date'];
      unset($postargs['date']);
    }

    // Get path to image file.
    if (isset($postargs['image'])) {
      if (is_array($postargs['image'])) {
        // Multiple images.
        $images = [];
        foreach ($postargs['image'] as $image_path) {
          $images[] =$this->getImage($image_path);
        }
        $postargs['image'] = $images;
      }
      else {
        // Single image.
        $image_path = $postargs['image'];
        $postargs['image'] =$this->getImage($image_path);
      }
    }
    else {
      throw new \InvalidArgumentException('The POST body must contain an image
      parameter holding the location of the image to classify.');
    }

    $options['body'] = http_build_query($postargs);

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
                'default_common_name' => $taxa[0]['default_common_name'],
                'external_key' => $taxa[0]['external_key'],
                'organism_key' => $taxa[0]['organism_key'],
                'identification_difficulty' => $taxa[0]['identification_difficulty'],
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

    // Append Record Cleaner opinions to suggestions.
    if ($this->configuration['cleaner']['enable']) {
      $data = $this->appendRecordCleanerData($data);
    }

    // Update response with filtered results.
    $classification['suggestions'] = $data;
    $response->setContent(json_encode($classification));
    return $response;
  }

  protected function getImage($image_path) {
    if (substr($image_path, 0, 4) == 'http') {
      // The image has to be obtained from a url.
      // Do a head request to determine the content-type.
      $handle = curl_init($image_path);
      curl_setopt($handle, CURLOPT_NOBODY, TRUE);
      // Some hosts reject requests without user agent, apparently.
      // https://stackoverflow.com/a/6497248
      curl_setopt($handle, CURLOPT_USERAGENT, 'Mozilla');
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

    return $image_path;
  }

  /**
   * Append Record Cleaner opinions to suggestions.
   *
   * @param array $suggestions
   *   Array of suggestions.
   *
   * @return array
   *   Updated array of suggestions.
   */
  protected function appendRecordCleanerData($suggestions) {

    $results = '';

    // Authenticate.
    try {
      $token = $this->getRecordCleanerAuth();
    }
    catch (\Exception $e) {
      $logger = $this->getLogger('indicia_ai');
      $logger->error($e->getMessage());
      $results = 'error';
    }

    // Check parameters are set.
    if ($this->sref == NULL || $this->date == NULL) {
      $results = 'omit';
    }

    if ($results == '') {
      // Verify.
      try {
        $results = $this->getRecordCleanerVerify($token, $suggestions);
      }
      catch (\Exception $e) {
        $logger = $this->getLogger('indicia_ai');
        $logger->error($e->getMessage());
        $results = 'error';
      }
    }

    // Probably an input validation error.
    if (is_array($results) && !array_key_exists('records', $results)) {
      $results = 'invalid';
    }

    $i = 0;
    // Annotate suggestions.
    foreach ($suggestions as &$suggestion) {
      $i++;
      if (is_array($results)) {
        // Extract corresponding record from Record Cleaner response.
        foreach ($results['records'] as $record) {
          if ($record['id'] == $i) {
            $suggestion['record_cleaner'] = $record['result'];
            break;}
        }
      }
      else {
        $suggestion['record_cleaner'] = $results;
      }
    }

    return $suggestions;
  }

  /**
   * Authenticate with Record Cleaner to obtain token.
   *
   * @return string The token.
   */
  protected function getRecordCleanerAuth() {

    $url = $this->configuration['cleaner']['url'] . '/token';
    $postargs = [
      'grant_type' => 'password',
      'username' => $this->configuration['cleaner']['username'],
      'password' => $this->configuration['cleaner']['password'],
      'scope' => '',
    ];
    $postargs = http_build_query($postargs);

    $session = curl_init($url);
    curl_setopt($session, CURLOPT_POST, TRUE);
    curl_setopt($session, CURLOPT_POSTFIELDS, $postargs);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($session);
    curl_close($session);

    if ($response == FALSE) {
      throw new \Exception("Record Cleaner token request failed.");
    }

    $response = json_decode($response, TRUE);
    if (!array_key_exists('access_token', $response)) {
      throw new \Exception("Record Cleaner authentication failed.");
    }
    $token = $response['access_token'];
    return $token;

  }

  /**
   * Verify suggestions with Record Cleaner.
   *
   * @param string $token
   *   Authentication token.
   * @param array $suggestions
   *   Array of suggestions.
   *
   * @return array
   *   Record Cleaner response.
   */
  protected function getRecordCleanerVerify($token, $suggestions) {
    // Build the records array to send to Record Cleaner.
    $records = [];
    $id = 0;
    foreach ($suggestions as $suggestion) {
      $records[] = [
        'id' => ++$id,
        'name' => $suggestion['taxon'],
        'date' => $this->date,
        'sref' => $this->sref,
      ];
    }

    // Combine with the rules list parameter.
    $postjson = json_encode([
      'org_group_rules_list' => $this->orgGroupRulesList,
      'records' => $records,
    ]);

    $url = $this->configuration['cleaner']['url'] . '/verify';
    $session = curl_init($url);
    curl_setopt($session, CURLOPT_HTTPHEADER, [
      "Authorization: Bearer $token",
      "Content-Type: application/json",
    ]);
    curl_setopt($session, CURLOPT_POST, TRUE);
    curl_setopt($session, CURLOPT_POSTFIELDS, $postjson);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($session);
    curl_close($session);
    if ($response == FALSE) {
      throw new \Exception("Record Cleaner verification failed.");
    }

    $result = json_decode($response, TRUE);
    return $result;
  }

}
