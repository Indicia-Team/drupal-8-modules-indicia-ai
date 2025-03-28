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
   * The classifier plugin being used.
   *
   */
  private $plugin;


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

    // Apply plugin specific headers.
    $headers = $this->plugin->calculateHeaders($headers);

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

    switch($path) {
      case '/nia':
        $plugin_id = 'nia';
        break;
      case '/plantnet':
        $plugin_id = 'plantnet';
        break;
      default:
        $plugin_id = 'nia';
    }

    // Instantiate the appropriate proxy plugin.
    $manager = \Drupal::service('Drupal\api_proxy\Plugin\HttpApiPluginManager');
    $this->plugin = $manager->createInstance($plugin_id);

    // Apply plugin specific pre-processing.
    list($method, $uri, $headers, $query) = $this->plugin->preprocessIncoming(
      $method, $uri, $headers, $query);

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

    // Ensure we have an image parameter.
    if (!isset($postargs['image'])) {
      throw new \InvalidArgumentException('The POST body must contain an image
      parameter holding the location of the image to classify.');
    }

    // The parameter can be a string or an array. Convert to an array.
    if (is_array($postargs['image'])) {
      $images = $postargs['image'];
    }
    else {
      $images = [$postargs['image']];
    }

    // Obtain a local copy of any remote images.
    foreach ($images as $image) {
      $local_images[] =$this->getImage($image);
    }
    $postargs['image'] = $local_images;

    // Reconstruct the postargs array in to a valid body.
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

    // Apply plugin specific pre-processing.
    $options = $this->plugin->preprocessOutgoingRequestOptions($options);

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessOutgoing(Response $response): Response {
    // Apply plugin specific post-processing.
    $response = $this->plugin->postprocessOutgoing($response);

    $classification = json_decode($response->getContent(), TRUE);

    $suggestions = $classification['suggestions'];
    // Filter out suggestions by probability threshold.
    $suggestions = $this->filterByProbability($suggestions);

    // Sort suggestions by probability descending.
    usort($suggestions, function($v1, $v2){
      $v2['probability'] <=> $v1['probability'];
    });

    // Append Indicia data to suggestions filtering by taxon group at the same
    // time if such a parameter was supplied.
    if (!empty($this->taxonListId)) {
      $suggestions = $this->appendIndiciaData($suggestions);
    }

    // Limit suggestions by number.
    $suggestions = array_slice(
      $suggestions, 0, $this->configuration['classify']['suggestions']);

    // Append Record Cleaner opinions to suggestions.
    if ($this->configuration['cleaner']['enable']) {
      $suggestions = $this->appendRecordCleanerData($suggestions);
    }

    // Update response with filtered results.
    $classification['suggestions'] = $suggestions;
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
   * Remove suggestions falling below probability threshold.
   *
   * @param array $suggestions
   *   Array of suggestions.
   *
   * @return array
   *   Updated array of suggestions.
   */
  protected function filterByProbability($suggestions) {
    $filtered_suggestions = [];
    foreach ($suggestions as $suggestion) {
      // Find suggestions above the threshold.
      if ($suggestion['probability'] >= $this->configuration['classify']['threshold']) {
        $filtered_suggestions[] = $suggestion;
      }
    }
    return $filtered_suggestions;
  }

  /**
   * Append species data from Indicia Warehouse to suggestions.
   *
   * @param array $suggestions
   *   Array of suggestions.
   *
   * @return array
   *   Updated array of suggestions.
   */
  protected function appendIndiciaData($suggestions) {
    $connection = iform_get_connection_details(null);
    $readAuth = \data_entry_helper::get_read_auth(
      $connection['website_id'], $connection['password']
    );

    $augmented_suggestions = [];
    foreach($suggestions as $suggestion) {
      $warehouse_data = [];
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
        if (
          array_key_exists('taxon_group_id', $warehouse_data) &&
          !in_array($warehouse_data['taxon_group_id'], $this->taxonGroupIds)
        ) {
          // Skip to next suggestion.
          continue;
        }
      }

      // Suggestions are kept if they are not found in the Indicia warehouse.
      // It can be argued that they should be removed but, for now, it is
      // informative about possible mis-matches in taxon names.
      $augmented_suggestions[] = [
        'probability' => $suggestion['probability'],
        'classifier_taxon' => $suggestion['taxon'],
      ] + $warehouse_data;

      if (
        count($augmented_suggestions) ==
        $this->configuration['classify']['suggestions']
      ) {
        // Stop looking up suggestions once we have enough.
        break;
      }
    }

    return $augmented_suggestions;
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
     // Iterate with reference so we can modify suggestion.
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
      $id++;
      // Skip suggestions that did not get found in the warehouse lookup.
      if (array_key_exists('taxon', $suggestion)) {
      $records[] = [
          'id' => $id,
        'name' => $suggestion['taxon'],
        'date' => $this->date,
        'sref' => $this->sref,
      ];
      }
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
