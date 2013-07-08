<?php
/**
  Core JSON API
*/


// Error Codes are negative, Warning codes are positive
define('WCAPI_EXPECTED_ARGUMENT',             -1);
define('WCAPI_NOT_IMPLEMENTED',               -2);
define('WCAPI_UNEXPECTED_ERROR',              -3);
define('WCAPI_INVALID_CREDENTIALS',           -4);
define('WCAPI_BAD_ARGUMENT',                  -5);
define('WCAPI_CANNOT_INSERT_RECORD',          -5);

define('WCAPI_PRODUCT_NOT_EXISTS', 1);
require_once( plugin_dir_path(__FILE__) . '/class-rede-helpers.php' );
require_once( plugin_dir_path(__FILE__) . '/class-wc-json-api-result.php' );
require_once( plugin_dir_path(__FILE__) . '/class-wc-json-api-product.php' );
class WooCommerce_JSON_API {
    // Call this function to setup a new response
  private $helpers;
  private $result;
  
  public function __construct() {
    $this->helpers = new RedEHelpers();
    $this->result = null;
  }
  /**
    This function is the single entry point into the API.
    
    The order of operations goes like this:
    
    1) A new result object is created.
    2) Check to see if it's a valid API User, if not, do stuff and quit
    3) Check to see if the method requested has been implemented
    4) If it's implemented, call and turn over control to the method
    
    This function takes a single hash,  usually $_REQUEST
    
    WHY? 
    
    Well, as you will notice with WooCommerce, there is an irritatingly large
    dependence on _defined_ and $_GET/$_POST variables, throughout their plugin,
    each function "depends" on request state, which is fine, except this
    violates 'dependency injection'. We don't know where data might come from
    in the future, what if another plugin wants to call this one inside of PHP
    within a request, multiple times? 
    
    No module should ever 'depend' on objects outside of itself, they should be
    provided with operating data, or 'injected' with it.
    
    There is nothing 'wrong' with the way WooCommerce does things, only it leads
    to a certain inflexibility in what you can do with it.
  */
  public function route( $params ) {
    $this->createNewResult( $params );
    if ( ! $this->isValidAPIUser( $params ) ) {
      $this->result->addError( __('Not a valid API User', 'woocommerce_json_api' ), WCAPI_INVALID_CREDENTIALS );
      $this->done();
    }
    if ( $this->isImplemented( $params ) ) {
      try {
        $this->{ $params['proc'] }($params);
      } catch ( Exception $e ) {
        $this->unexpectedError( $params, $e);
      }
    } else {
      $this->notImplemented( $params );
    }
  }
  
  private function isImplemented( $params ) {
    $implemented_methods = array(
      'get_system_time',
      'get_products',
      'get_categories',
      'get_taxes',
      'get_shipping_methods',
      'get_payment_gateways',
      
      // Write capable methods
      
      'set_products',
      'set_categories',
    );
    if (isset($params['proc']) &&  $this->helpers->inArray( $params['proc'], $implemented_methods) ) {
      return true;
    } else {
      return false;
    }
  }
  
  private function notImplemented( $params ) {
    $this->createNewResult( $params );
    if ( !isset($params['proc']) ) {
      $this->result->addError( 
          __('Expected argument was not present', 'woocommerce_json_api') . ' `proc`',
           WCAPI_EXPECTED_ARGUMENT );
    }
    $this->result->addError( __('That API method has not been implemented', 'woocommerce_json_api' ), WCAPI_NOT_IMPLEMENTED );
    $this->done();
  }
  
  
  private function unexpectedError( $params, $error ) {
    $this->createNewResult( $params );
    $this->result->addError( __('An unexpected error has occured', 'woocommerce_json_api' ) . $error->getMessage(), WCAPI_UNEXPECTED_ERROR );
    $this->done();
  }
  
  
  private function createNewResult($params) {
    if ( ! $this->result ) {
      $this->result = new WooCommerce_JSON_API_Result();
      $this->result->setParams( $params );
    }
  }
  
  private function done() {
    header("Content-type: application/json");
    echo( $this->result->asJSON() );
    die;
  }
  
  private function isValidAPIUser( $params ) {
    if ( ! isset($params['arguments']) ) {
      $this->result->addError( __( 'Missing `arguments` key','woocommerce_json_api' ),WCAPI_EXPECTED_ARGUMENT );
      return false;
    }
    if ( ! isset( $params['arguments']['token'] ) ) {
      $this->result->addError( __( 'Missing `token` in `arguments`','woocommerce_json_api' ),WCAPI_EXPECTED_ARGUMENT );
      return false;
    }
    $key = $this->helpers->getPluginPrefix() . '_settings';
    $args = array(
      'blog_id' => $GLOBALS['blog_id'],
      'meta_key' => $key
    );
    $users = get_users( $args );
    foreach ($users as $user) {
      $meta = unserialize( get_user_meta( $user->ID, $key, true ) );
      if (isset( $meta['token']) &&  $params['arguments']['token'] == $meta['token']) {
        $this->logUserIn($user);
        return true;
      }
    }
    return false;
  }
  private function logUserIn( $user ) {
    wp_set_current_user($user->ID);
    wp_set_auth_cookie( $user->ID, false, is_ssl() );
  }
  private function translateCategoryAttributes( $cobj ) {
    $thumb_id = get_woocommerce_term_meta( $cobj->term_id, 'thumbnail_id', true);
    $image = wp_get_attachment_url( $thumb_id );
    return array( 
      'name' => $cobj->name,
      'slug' => $cobj->slug,
      'permalink' => get_term_link($cobj,'product_cat'),
      'id' => $cobj->term_id,
      'parent_id' => $cobj->parent,
      'group_id' => $cobj->term_group,
      'taxonomy_id' => $cobj->term_taxonomy_id,
      'image' => $image,
    );
  }
  private function translateProductAttributes($product) {
    global $wpdb;
    $permalinks 	                  = get_option( 'woocommerce_permalinks' );
    $product_category_slug 	        = empty( $permalinks['category_base'] ) ? _x( 'product-category', 'slug', 'woocommerce' ) : $permalinks['category_base'];
		$shop_page_id 	                = woocommerce_get_page_id( 'shop' );
    $meta                           = array();
    $post                           = $product->get_post_data();
    //$meta['query'] = "SELECT `meta_key`, `meta_value` from {$wpdb->postmeta} where `post_id` = '{$post->ID}'";
    $result           = $wpdb->query( "SELECT `meta_key`, `meta_value` from {$wpdb->postmeta} where `post_id` = '{$post->ID}'");
    foreach ($wpdb->last_result as $k => $v) {
      $meta[ $v->meta_key] = $v->meta_value;
    }
    $category_objs = woocommerce_get_product_terms($post->ID, 'product_cat', 'all');
    $categories = array();

    foreach ( $category_objs as $cobj ) {
      $categories[] = $this->translateCategoryAttributes( $cobj );
    }
    $attrs = array(
      'id'   => $post->ID,
      'name' => $product->get_title(),
      'description' => $product->get_post_data()->post_content,
      'slug' => $post->post_name,
      'permalink' => get_permalink( $post->ID ),
      'price' => array( 
        'amount' => $product->get_price(),
        'currency' => get_woocommerce_currency(),
        'symbol' => get_woocommerce_currency_symbol(),
        'taxable' => $product->is_taxable(),
      ),
      'sku' => $product->get_sku(),
      'stock' => array(
        'managed' => $product->managing_stock(),
        'for_sale' => $product->get_stock_quantity(),
        'in_stock' => $product->get_total_stock(),
        'downloadable' => $product->is_downloadable(),
        'virtual' => $product->is_virtual(),
        'sold_individually' => $product->is_sold_individually(),
        'download_paths' => isset($meta['_file_paths']) ? maybe_unserialize($meta['_file_paths']) : array(),
      ),
      'categories' => $categories,
    );
    
    
    
    return $attrs;
  }
  private function translateTaxRateAttributes( $rate ) {
    $attrs = array();
    foreach ( $rate as $k=>$v ) {
      $attrs[ str_replace('tax_rate_','',$k) ] = $v;
    }
    return $attrs;
  }
  /*******************************************************************
  *                         Core API Functions                       *
  ********************************************************************
  * These functions are called as a result of what was set in the
  * JSON Object for `proc`.
  ********************************************************************/
  
  private function get_system_time( $params ) {
    
    $data = array(
      'timezone'  => date_default_timezone_get(),
      'date'      => date("Y-m-d"),
      'time'      => date("h:i:s",time())
    );
    $this->result->addPayload($data);
    $this->done();
  }
  
  /**
    This is the single entry point for fetching products, ordering, paging, as well
    as "finding" by ID or SKU.
  */
  private function get_products( $params ) {
    global $wpdb;
    $allowed_order_bys = array('ID','post_title','post_date','post_author','post_modified');
    /**
      Read this section to get familiar with the arguments of this method.
    */
    $posts_per_page = $this->helpers->orEq( $params['arguments'], 'per_page', 15 ); 
    $paged          = $this->helpers->orEq( $params['arguments'], 'page', 0 );
    $order_by       = $this->helpers->orEq( $params['arguments'], 'order_by', 'ID');
    $order          = $this->helpers->orEq( $params['arguments'], 'order', 'ASC');
    $ids            = $this->helpers->orEq( $params['arguments'], 'ids', false);
    $skus           = $this->helpers->orEq( $params['arguments'], 'skus', false);
    
    $by_ids = true;
    if ( ! $this->helpers->inArray($order_by,$allowed_order_bys) ) {
      $this->result->addError( __('order_by must be one of these:','woocommerce_json_api') . join( $allowed_order_bys, ','), WCAPI_BAD_ARGUMENT );
      $this->done();
      return;
    }
    if ( ! $ids && ! $skus ) {
      
      $posts = WC_JSON_API_Product::all()->per($posts_per_page)->page($paged)->fetch(function ( $result) {
        return $result['id'];
	    });
	  } else if ( $ids ) {
	  
	    $posts = $ids;
	    
	  } else if ( $skus ) {
	  
	    $posts = array();
	    foreach ($skus as $sku) {
	      $pid = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1",$sku) );
	      if ( ! $pid ) {
	        $this->result->addWarning( $sku . ': ' . __('Product does not exist','woocommerce_json_api'), WCAPI_PRODUCT_NOT_EXISTS, array( 'sku' => $sku) );
	      } else {
	        $posts[] = $pid;
	      }
	    }
	    
	  }

	  $products = array();
    foreach ( $posts as $post_id) {
      $post = WC_JSON_API_Product::find($post_id);
      if ( !$post ) {
        $this->result->addWarning( $post_id. ': ' . __('Product does not exist','woocommerce_json_api'), WCAPI_PRODUCT_NOT_EXISTS, array( 'id' => $post_id) );
      } else {
        $products[] = $post->asApiArray();
      }
      
    }
    // We manage the array ourselves, so call setPayload, instead of addPayload
    $this->result->setPayload($products);

	  $this->done();
  }
  /*
    Similar to get products, in fact, we should be able to resuse te response
    for that call to edit the products thate were returned.
    
    WooCom has as kind of disconnected way of saving a product, coming from Rails,
    it's a bit jarring. Most of this function is taken from woocommerce_admin_product_quick_edit_save()
    
    It seems that Product objects don't know how to save themselves? This may not be the
    case but a cursory search didn't find out exactly how products are really
    being saved. That's no matter because they are mainly a custom post type anyway,
    and most fields attached to them are just post_meta fields that are easy enough
    to find in the DB.
    
    There's certainly a more elegant solution to be found, but this has to get
    up and working, and be pretty straightforward/explicit. If I had the time,
    I'd write a custom Product class that knows how to save itself,
    and then just make setter methods modify internal state and then abstract out.
  */
    // FIXME: We need some way to ensure that adding of products is not
    // exploited. we need to track errors, and temporarily ban users with
    // too many. We need a way to lift the ban in the interface and so on.
  private function set_products( $params ) {
    
    $products = $this->helpers->orEq( $params, 'payload', array() );
    foreach ( $products as $attrs) {
      if (isset($attrs['id'])) {
        $product = WC_JSON_API_Product::find($attrs['id']);
      } else if ( isset($attrs['sku'])) {
        $product = WC_JSON_API_Product::find_by_sku($attrs['sku']);
      }
      if ($product->isValid()) {
        $product->fromApiArray( $attrs );
        $product->update()->done();
      } else {
        $this->result->addWarning( 
          __(
              'Product does not exist.',
              'woocommerce_json_api'
            ),
          WCAPI_PRODUCT_NOT_EXISTS, 
          array( 
            'id' => isset($attrs['id']) ? $attrs['id'] : 'none',
            'sku' => isset($attrs['sku']) ? $attrs['sku'] : 'none',
          )
        );
        // Let's create the product if it doesn't exist.
        $product = new WC_JSON_API_Product();
        $product->create( $attrs );
        if ( ! $product->isValid() ) {
          return $this->done();
        }
      }
    }
    $this->done();

  }

  
  /**
     Get product categories
  */
  private function get_categories( $params ) {
  
    $allowed_order_bys = array('id','count','name','slug');
    
    $order_by       = $this->helpers->orEq( $params['arguments'], 'order_by', 'name');
    if ( ! $this->helpers->inArray($order_by,$allowed_order_bys) ) {
      $this->result->addError( __('order_by must be one of these:','woocommerce_json_api') . join( $allowed_order_bys, ','), WCAPI_BAD_ARGUMENT );
      $this->done();
      return;
    }
    $order          = $this->helpers->orEq( $params['arguments'], 'order', 'ASC');
    $ids            = $this->helpers->orEq( $params['arguments'], 'ids', false);
    
    $hide_empty     = $this->helpers->orEq( $params['arguments'], 'hide_empty', false);
    
    $args = array(
  	  'fields'         => 'ids',
      'order_by'       => $order_by,
      'order'          => $order,
    );
    
    if ($ids) {
      $args['include'] = $ids;
    }
    
    $categories = get_terms('product_cat', $args);
    foreach ( $categories as $id ) {
      $category = WC_JSON_API_Category::find( $id );
      $this->result->addPayload( $category->asApiArray() );
    }
    $this->done();
  }
  
  private function set_categories( $params ) {
    $categories = $this->helpers->orEq( $params, 'payload', array());
    foreach ( $categories as $category ) {
      $actual = WC_JSON_API_Category::find_by_name( $category['name'] );
      print_r($actual->asApiArray());
    }
    $this->done();
  }
  /**
    Get tax rates defined for store
  */
  private function get_taxes( $params ) {
    global $wpdb;
    
    $tax_classes = explode("\n",get_option('woocommerce_tax_classes'));
    $tax_classes = array_merge($tax_classes, array(''));
    
    $tax_rates = array();
    
    foreach ( $tax_classes as $tax) {
      $name = $tax;
      if ( $name == '' ) {
        $name = "DefaultRate";
      } 
      // Never have a select * without a limit statement.
      $found_rates = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates where tax_rate_class = %s LIMIT %d",$tax,100) );
      $rates = array();
      foreach ( $found_rates as $rate ) {
       
        $rates[] = $this->translateTaxRateAttributes($rate);
      }
      $tax_rates[] = array(
        'name' => $name,
        'rates' => $rates
      );
    }
    $this->result->setPayload($tax_rates); 
    $this->done();   
  }
  /**
    WooCommerce handles shipping methods on a per class/instance basis. So in order to have a
    shipping method, we must have a class file that registers itself with 'woocommerce_shipping_methods'.
  */
  private function get_shipping_methods( $params ) {
    $klass = new WC_Shipping();
    $klass->load_shipping_methods();
    $methods = array();
    foreach ( $klass->shipping_methods as $sm ) {
      $methods[] = array(
        'id' => $sm->id,
        'name' => $sm->title,
        'display_name' => $sm->method_title,
        'enabled' => $sm->enabled,
        'settings' => $sm->settings,
        'plugin_id' => $sm->plugin_id,
      );
    }
    $this->result->setPayload( $methods );
    $this->done();
  }
  
  /**
    Get info on Payment Gateways
  */
  private function get_payment_gateways( $params ) {
    $klass = new WC_Payment_Gateways();
    foreach ( $klass->payment_gateways as $sm ) {
      $methods[] = array(
        'id' => $sm->id,
        'name' => $sm->title,
        'display_name' => $sm->method_title,
        'enabled' => $sm->enabled,
        'settings' => $sm->settings,
        'plugin_id' => $sm->plugin_id,
      );
    }
    $this->result->setPayload( $methods );
    $this->done();
  }
}
