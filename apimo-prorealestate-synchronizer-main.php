<?php

// Includes the core classes
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/plugin.php');
if (!class_exists('WP_Http')) {
  require_once(ABSPATH . WPINC . '/class-http.php');
}

class ApimoProrealestateSynchronizer
{
  /**
   * Instance of this class
   *
   * @var ApimoProrealestateSynchronizer
   */
  private static $instance;

  /**
   * @var string
   */
  private $siteLanguage;

  /**
   * Constructor
   *
   * Initializes the plugin so that the synchronization begins automatically every hour,
   * when a visitor comes to the website
   */
  public function __construct()
  {
    // Retrieve site language
    $this->siteLanguage = $this->getSiteLanguage();

    // Trigger the synchronizer event every hour only if the API settings have been configured
    if (is_array(get_option('apimo_prorealestate_synchronizer_settings_options'))) {
      if (
        isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_provider']) &&
        isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_token']) &&
        isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_agency']) &&
        isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_data_limit'])
      ) {
        add_filter('cron_schedules', array($this, 'my_cron_schedules'));
        add_action(
          'apimo_prorealestate_synchronizer_hourly_event',
          array($this, 'synchronize')
        );

        // For debug only, you can uncomment this line to trigger the event every time the blog is loaded
        //add_action('init', array($this, 'synchronize'));
      }
    }
  }

  /**
   * Retrieve site language
   */
  private function getSiteLanguage()
  {
    return substr(get_bloginfo('language'), 0, 2);
  }

  /**
   * Creates an instance of this class
   *
   * @access public
   * @return ApimoProrealestateSynchronizer An instance of this class
   */
  public static function getInstance()
  {
    if (null === self::$instance) {
      self::$instance = new self;
    }

    return self::$instance;
  }


  public function my_cron_schedules($schedules)
  {
    if (!isset($schedules["2min"])) {
      $schedules["2min"] = array(
        'interval' => 2 * 60,
        'display' => __('Once every 2 minutes')
      );
    }

    if (!isset($schedules["10min"])) {
      $schedules["10min"] = array(
        'interval' => 10 * 60,
        'display' => __('Once every 10 minutes')
      );
    }
    return $schedules;
  }

  private function callCurrencyConverter($apiKey, $baseCurrency)
  {
    $request = new WP_Http;
    $response = $request->request("https://v6.exchangerate-api.com/v6/" . $apiKey . "/latest/" . $baseCurrency, array(
      'method' => "GET",
    ));

    if (is_array($response) && !is_wp_error($response)) {
      if ($response["response"]["code"] != 200) {
        return array(
          'status' => $response["response"]["code"],
          'body' => "Invalid"
        );
      }

      return array(
        'status' => $response["response"]["code"],
        'body' => json_decode($response["body"]),
      );
    } else {
      return array(
        'status' => 500,
        'body' => $response->get_error_message()
      );
    }
  }

  private function getCurrencyValue($apiKey, $reqCurrency, $originalValue, $originalCurrency)
  {
    if (false === ($rates = get_transient('currency_data'))) {
      error_log("Currency data not available");
      $data = $this->callCurrencyConverter($apiKey, $reqCurrency);
      if ($data['status'] != 200) {
        error_log("Currency data failed");
        return $originalValue;
      }
      $rates = $data['body']->conversion_rates;
      set_transient("currency_data", $rates, 3600 * 24);
    }

    if (!isset($rates->$originalCurrency)) {
      error_log("Currency not available");
      return $originalValue;
    }

    return round($originalValue / $rates->$originalCurrency);
  }

  private function getAPIMOData()
  {
    $data = get_transient('apimo_cached_data');

    if ($data) {
      error_log("Found data from cache");
      return $data;
    }

    $limit = 3000;
    // Gets the properties
    $return = $this->callApimoAPI(
      'https://api.apimo.pro/agencies/'
        . get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_agency']
        . '/properties',
      'GET',
      [
        "limit" => $limit
      ]
    );

    // Parses the JSON into an array of properties object
    $jsonBody = json_decode($return['body']);

    if (!is_object($jsonBody) || !isset($jsonBody->properties)) {
      error_log("Error fetching data");
      error_log($jsonBody);
      return $jsonBody;
    }

    set_transient('apimo_cached_data', $jsonBody, 7200);

    return $jsonBody;
  }

  /**
   * Synchronizes Apimo and Pro Real Estate plugnins estates
   *
   * @access public
   */
  public function synchronize()
  {
    error_log("sync: start");
    set_time_limit(180);

    $jsonBody = $this->getAPIMOData();
    if (!is_object($jsonBody) || !isset($jsonBody->properties)) {
      return;
    }

    $properties = $jsonBody->properties;
    $propertyIDs = array();
    $dataOffset = get_option('apimo_data_offset', 0);
    $dataLimit = get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_data_limit'];
    error_log("Data offset is " . $dataOffset);
    if (is_array($properties)) {
      $updateProperties = array_slice($properties, $dataOffset, $dataLimit);
      foreach ($updateProperties as $property) {
        error_log("start sync for " . (isset($property->id) ? $property->id : ""));
        // Parse the property object
        $data = $this->parseJSONOutput($property);
        if (null !== $data) {
          // Creates or updates a listing
          $this->manageListingPost($data);
        }
        error_log("done sync for " . (isset($property->id) ? $property->id : ""));
      }

      foreach ($properties as $property) {
        $propertyIDs[] = $property->id;
      }

      $this->deleteOldListingPost($propertyIDs);

      $totalListings = count($propertyIDs);
      $dataOffset += $dataLimit;
      if ($dataOffset >= $totalListings) {
        $dataOffset = 0;
      }

      update_option('apimo_data_offset', $dataOffset);
      error_log("Next data offset is " . $dataOffset);
    }
    error_log("sync: end");
  }

  private function getPropertyPrice($value, $currency)
  {
    if (!$value) {
      return  __('Price on ask');
    } else {
      $baseCurrency = isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_data_currency'])
        ? get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_data_currency'] : '';

      if ($currency == '' || $baseCurrency == '' || $currency == $baseCurrency) {
        error_log("Not calling anything " . $currency . " " . $baseCurrency);
        return $value;
      }

      $apiKey = isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_data_currency_key'])
        ? get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_data_currency_key'] : '';

      if ($apiKey == '') {
        return $value;
      }

      $value = $value + 0; // convert to number

      $value = $this->getCurrencyValue($apiKey, $baseCurrency, $value, $currency);
      error_log("Final value " . $value);
      return $value;
    }
  }

  /**
   * Parses a JSON body and extracts selected values
   *
   * @access private
   * @param stdClass $property
   * @return array $data
   */
  private function parseJSONOutput($property)
  {

    $price = $this->getPropertyPrice($property->price->value, $property->price->currency);

    $data = array(
      'user' => $property->user,
      'updated_at' => $property->updated_at,
      'postTitle' => array(),
      'postContent' => array(),
      'images' => array(),
      'customMetaAltTitle' => $property->address,
      'customMetaPrice' => $price,
      'customMetaPricePrefix' => '',
      'customMetaPricePostfix' => '',
      'customMetaSqFt' => preg_replace('#,#', '.', $property->area->value),
      'customMetaVideoURL' => '',
      'customMetaMLS' => $property->id,
      'customMetaLatLng' => ($property->latitude && $property->longitude
        ? $property->latitude . ', ' . $property->longitude
        : ''),
      'customMetaExpireListing' => '',
      'ct_property_type' => $property->type,
      'rooms' => 0,
      'beds' => 0,
      'customTaxBeds' => 0,
      'customTaxBaths' => 0,
      'ct_ct_status' => '',
      'customTaxCity' => $property->city->name,
      'customTaxState' => '',
      'customTaxZip' => $property->city->zipcode,
      'customTaxCountry' => $property->country,
      'customTaxCommunity' => '',
      'customTaxFeat' => '',
      'customFeatures' => [],
      'customSubType' => isset($property->subtype) ? $property->subtype : 0,
    );

    foreach ($property->comments as $comment) {
      $data['postTitle'][$comment->language] = $comment->title;
      $data['postContent'][$comment->language] = $comment->comment;
    }

    $data['rooms'] = $property->rooms;
    $data['beds'] = $property->bedrooms;

    foreach ($property->areas as $area) {
      if (
        $area->type == 1 ||
        $area->type == 53 ||
        $area->type == 70
      ) {
        $data['customTaxBeds'] += $area->number;
      } else if (
        $area->type == 8 ||
        $area->type == 41 ||
        $area->type == 13 ||
        $area->type == 42
      ) {
        $data['customTaxBaths'] += $area->number;
      }
    }

    foreach ($property->pictures as $picture) {
      $data['images'][] = array(
        'id' => $picture->id,
        'url' => $picture->url,
        'rank' => $picture->rank
      );
    }

    if ($property->services && is_array($property->services)) {
      $customServices = [];
      foreach ($property->services as $service) {
        $customServices[] = $service;
      }
      $data["customFeatures"] = $customServices;
    }

    return $data;
  }

  /**
   * Creates or updates a listing post
   *
   * @param array $data
   */
  private function manageListingPost($data)
  {
    // Converts the data for later use
    $postTitle = $data['postTitle'][$this->siteLanguage];
    if ($postTitle == '') {
      foreach ($data['postTitle'] as $lang => $title) {
        $postTitle = $title;
      }
    }

    $postContent = $data['postContent'][$this->siteLanguage];
    if ($postContent == '') {
      foreach ($data['postContent'] as $lang => $title) {
        $postContent = $title;
      }
    }

    $postUpdatedAt = $data['updated_at'];
    $images = $data['images'];
    $customMetaAltTitle = $data['customMetaAltTitle'];
    $ctPrice = str_replace(array('.', ','), '', $data['customMetaPrice']);
    $customMetaPricePrefix = $data['customMetaPricePrefix'];
    $customMetaPricePostfix = $data['customMetaPricePostfix'];
    $customMetaSqFt = $data['customMetaSqFt'];
    $customMetaVideoURL = $data['customMetaVideoURL'];
    $customMetaMLS = $data['customMetaMLS'];
    $customMetaLatLng = $data['customMetaLatLng'];
    $customMetaExpireListing = $data['customMetaExpireListing'];
    $ctPropertyType = $data['ct_property_type'];
    $rooms = $data['rooms'];
    $beds = $data['beds'];
    $customTaxBeds = $data['customTaxBeds'];
    $customTaxBaths = $data['customTaxBaths'];
    $ctCtStatus = $data['ct_ct_status'];
    $customTaxCity = $data['customTaxCity'];
    $customTaxState = $data['customTaxState'];
    $customTaxZip = $data['customTaxZip'];
    $customTaxCountry = $data['customTaxCountry'];
    $customTaxCommunity = $data['customTaxCommunity'];
    $customTaxFeat = $data['customTaxFeat'];
    $customFeatures = $data['customFeatures'];
    $customSubType = $data['customSubType'];

    // Creates a listing post
    $postInformation = array(
      'post_title' => wp_strip_all_tags(trim($postTitle)),
      'post_content' => $postContent,
      'post_type' => 'listings',
      'post_status' => 'publish',
    );

    if (!$postTitle || $postTitle == "") {
      error_log("Empty post title");
      return;
    }

    // Verifies if the listing does not already exist
    $post = get_page_by_title(html_entity_decode($postTitle), OBJECT, 'listings');

    if (NULL === $post) {
      error_log("Post doesn't exist. Creating new");
      // Insert post and retrieve postId
      $postId = wp_insert_post($postInformation);
    } else {
      // Verifies if the property is not to old to be added
      if (strtotime($postUpdatedAt) <= strtotime('-5 days')) {
        error_log("Property too old to be updated");
        return;
      }

      $postInformation['ID'] = $post->ID;
      $postId = $post->ID;

      // Update post
      wp_update_post($postInformation);
    }

    // Delete attachments that has been removed
    $attachments = get_attached_media('image', $postId);
    foreach ($attachments as $attachment) {
      $imageStillPresent = false;
      foreach ($images as $image) {
        if (
          $attachment->post_content == $image['id'] &&
          $this->getFileNameFromURL($attachment->guid) == $this->getFileNameFromURL($image['url'])
        ) {
          $imageStillPresent = true;
        }
      }
      if (!$imageStillPresent) {
        wp_delete_attachment($attachment->ID, TRUE);
      }
    }

    // Updates the image and the featured image with the first given image
    $imagesIds = array();

    foreach ($images as $image) {
      // Tries to retrieve an existing media
      $media = $this->isMediaPosted($image['id']);

      // If the media does not exist, upload it
      if (!$media) {
        $media_res = media_sideload_image($image['url'], $postId);

        // Retrieve the last inserted media
        $args = array(
          'post_type' => 'attachment',
          'numberposts' => 1,
          'orderby' => 'date',
          'order' => 'DESC',
        );
        $medias = get_posts($args);

        // Just one media, but still an array returned by get_posts
        foreach ($medias as $attachment) {
          // Make sure the media's name is equal to the file name
          wp_update_post(array(
            'ID' => $attachment->ID,
            'post_name' => $postTitle,
            'post_title' => $postTitle,
            'post_content' => $image['id'],
          ));
          $media = $attachment;
        }
      } else {
        // Media already exists
        // If media is not attched to any parent
        if ($media->post_parent == 0) {
          // Check if this image is attached to this post
          $imageAttached = false;
          foreach ($attachments as $attachment) {
            if ($attachment->post_content == $image['id'] && $this->getFileNameFromURL($attachment->guid) == $this->getFileNameFromURL($image['url'])) {
              $imageAttached = true;
              break;
            }
          }
          // Media already exists but is not attached to post
          if (!$imageAttached) {
            // attach media to this post
            $update_media = array(
              'ID' => $media->ID,
              'post_parent' => $postId,
            );

            wp_update_post($update_media);
          }
        }
      }

      if (!empty($media) && !is_wp_error($media)) {
        $imagesIds[$image['rank']] = $media->ID;
      }

      // Set the first image as the thumbnail
      if ($image['rank'] == 1) {
        set_post_thumbnail($postId, $media->ID);
      }
    }

    $positions = implode(',', $imagesIds);
    update_post_meta($postId, '_ct_images_position', $positions);

    // Updates custom meta
    update_post_meta($postId, '_ct_listing_alt_title', esc_attr(strip_tags($customMetaAltTitle)));
    update_post_meta($postId, '_ct_price', esc_attr(strip_tags($ctPrice)));
    update_post_meta($postId, '_ct_price_prefix', esc_attr(strip_tags($customMetaPricePrefix)));
    update_post_meta($postId, '_ct_price_postfix', esc_attr(strip_tags($customMetaPricePostfix)));
    update_post_meta($postId, '_ct_sqft', esc_attr(strip_tags($customMetaSqFt)));
    update_post_meta($postId, '_ct_video', esc_attr(strip_tags($customMetaVideoURL)));
    update_post_meta($postId, '_ct_mls', esc_attr(strip_tags($customMetaMLS)));
    update_post_meta($postId, '_ct_latlng', esc_attr(strip_tags($customMetaLatLng)));
    update_post_meta($postId, '_ct_listing_expire', esc_attr(strip_tags($customMetaExpireListing)));

    // Updates custom taxonomies
    //wp_set_post_terms($postId, $ctPropertyType, 'property_type', FALSE);
    wp_set_post_terms($postId, $beds, 'beds', FALSE);
    wp_set_post_terms($postId, $customTaxBaths, 'baths', FALSE);
    wp_set_post_terms($postId, $ctCtStatus, 'ct_status', FALSE);
    wp_set_post_terms($postId, $customTaxState, 'state', FALSE);
    wp_set_post_terms($postId, $customTaxCity, 'city', FALSE);
    wp_set_post_terms($postId, $customTaxZip, 'zipcode', FALSE);
    wp_set_post_terms($postId, $customTaxCountry, 'country', FALSE);
    wp_set_post_terms($postId, $customTaxCommunity, 'community', FALSE);
    wp_set_post_terms($postId, $rooms, 'additional_features', FALSE);

    if (count($customFeatures) > 0) {
      $features = [
        4 => 1789,
        15 => 1790,
        27 => 1791,
        11 => 1792,
        1 => 1793,
        28 => 1794,
        47 => 1795,
        46 => 1796,
        18 => 1797,
        20 => 1798,
        31 => 1799,
        26 => 1800,
        19 => 1801,
        116 => 1802,
        23 => 1803,
        24 => 1804,
        17 => 1805,
        14 => 1806,
        44 => 1807,
        74 => 1808,
        29 => 1809,
        3 => 1810,
        6 => 1811,
        112 => 1812,
        5 => 1813,
        34 => 1814,
        7 => 1815,
        16 => 1816,
        12 => 1817,
        37 => 1818,
        45 => 1819,
        13 => 1820,
        36 => 1821,
        83 => 1822,
        82 => 1823,
        44 => 1824,
        46 => 1825,
      ];
      $feature_list = [];
      foreach ($customFeatures as $feature) {
        if (isset($features[$feature])) {
          $feature_list[] = $features[$feature];
        }
      }

      if (count($feature_list) > 0) {
        wp_set_post_terms($postId, $feature_list, 'additional_features', false);
      }
    }

    $propertyTypeMap = [
      2 => 1051,
      4 => 665,
      5 => 459,
      8 => 1786,
      9 => 665,
      12 => 665,
      14 => 1788,
      44 => 1787,
      46 => 1051,
      49 => 654,
      68 => 665,
    ];
    if(isset($propertyTypeMap[$customSubType])) {
      wp_set_post_terms($postId, $propertyTypeMap[$customSubType], 'property_type', false);
    }

  }

  /**
   * Delete old listings
   *
   * @param $properties
   */
  private function deleteOldListingPost($propertyIDs)
  {
    // Retrieve the current posts
    $posts = get_posts(array(
      'post_type' => 'listings',
      'numberposts' => -1,
    ));

    foreach ($posts as $post) {
      $remotePostID = get_post_meta($post->ID, "_ct_mls", true);
      if (!in_array($remotePostID, $propertyIDs)) {
        wp_delete_post($post->ID);
      }
    }
  }

  /**
   * Verifies if a media is already posted or not for a given image URL.
   *
   * @access private
   * @param int $imageId
   * @return object
   */
  private function isMediaPosted($imageId)
  {
    $args = array(
      'post_type' => 'attachment',
      'posts_per_page' => -1,
      'post_status' => 'any',
      'content' => $imageId,
    );

    $medias = ApimoProrealestateSynchronizer_PostsByContent::get($args);

    if (isset($medias) && is_array($medias)) {
      foreach ($medias as $media) {
        return $media;
      }
    }

    return null;
  }

  /**
   * Return the filename for a given URL.
   *
   * @access private
   * @param string $imageUrl
   * @return string $filename
   */
  private function getFileNameFromURL($imageUrl)
  {
    $imageUrlData = pathinfo($imageUrl);
    return $imageUrlData['filename'];
  }

  /**
   * Calls the Apimo API
   *
   * @access private
   * @param string $url The API URL to call
   * @param string $method The HTTP method to use
   * @param array $body The JSON formatted body to send to the API
   * @return array $response
   */
  private function callApimoAPI($url, $method, $body = null)
  {
    $headers = array(
      'Authorization' => 'Basic ' . base64_encode(
        get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_provider'] . ':' .
          get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_token']
      ),
      'content-type' => 'application/json',
    );

    if (null === $body || !is_array($body)) {
      $body = array();
    }

    if (!isset($body['limit'])) {
      $body['limit'] = 100;
    }
    if (!isset($body['offset'])) {
      $body['offset'] = 0;
    }

    $request = new WP_Http;
    $response = $request->request($url, array(
      'method' => $method,
      'headers' => $headers,
      'body' => $body,
    ));

    if (is_array($response) && !is_wp_error($response)) {
      $headers = $response['headers']; // array of http header lines
      $body = $response['body']; // use the content
    } else {
      $body = $response->get_error_message();
    }

    return array(
      'headers' => $headers,
      'body' => $body,
    );
  }

  /**
   * Activation hook
   */
  public function install()
  {
    error_log("Installing APIMO");
    if (!wp_next_scheduled('apimo_prorealestate_synchronizer_hourly_event')) {
      error_log("Setting event");
      wp_schedule_event(time(), '10min', 'apimo_prorealestate_synchronizer_hourly_event');
      error_log("Install APIMO Done");
    }
  }

  /**
   * Deactivation hook
   */
  public function uninstall()
  {
    wp_clear_scheduled_hook('apimo_prorealestate_synchronizer_hourly_event');
    delete_option('apimo_data_offset');
    delete_transient('apimo_cached_data');
  }
}
