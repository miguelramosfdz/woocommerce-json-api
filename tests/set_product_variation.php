<?php
require_once "functions.php";
include "config.php";
$Header("Setting Product Variation");

$r = rand(1,9999999);
$sr = rand(1,5);
$p =$sr * 1.25;


$master_product_data = array(
  'name' => "An API Created Variable Product",
  'price' => $p,
  'sku' => "API$r",
  'visibility' => 'visible',
  'product_type' => 'variable',
  'type' => 'product',
  'status' => 'instock',
  'attributes' => array(
    'size' => array(
      'name' => 'Size',
      'value' => array('Small','Medium','Large'),
      'is_variation' => 'yes',
      'is_visible' => 'yes',
      'is_taxonomy' => 'no'
    )

  ),
);

// Try uploading an image

$new_product_data = array(
  'name' => "An API Created Product 203 Variation #1",
  'price' => $p,
  'sku' => "API{$r}V1",
  'visibility' => 'visible',
  'product_type' => 'simple',
  'type' => 'product_variation',
  'status' => 'instock',
  'size_attribute' => 'small', // this is our dynamic attribute
);
$new_product_data2 = array(
  'name' => "An API Created Product 203 Variation #2",
  'price' => $p,
  'sku' => "API{$r}V2",
  'visibility' => 'visible',
  'product_type' => 'simple',
  'type' => 'product_variation',
  'status' => 'instock',
  'size_attribute' => 'medium', // this is our dynamic attribute
);

$master_product_data['variations'] = array($new_product_data,$new_product_data2);
$data = array(
  'action'      => 'woocommerce_json_api',
  'proc'        => 'set_products',
  'arguments'   => array(
    'token' => $token,
  ),
  'payload' => array($master_product_data),
  'model_filters' => array(
    /*
     * We need to edit a dynamic attribute, so we have
     * to let the model layer know it should load up 
     * a specific attribute.
     * 
     * In this case, we have an attribute called Size,
     * WooCom will save this in the db as: attribute_size
     */
    'WCAPI_product_meta_attributes_table' => array(
      'size_attribute' => array(
       'name' => 'attribute_size',          
       'type' => 'string', 
       'values' => array(
        'small',
        'medium',
        'large',
       ),
       'sizehint' => 2
     ),
    )

  ),
);

$result = curl_post($url,$data);
$result = json_decode($result,true); 
$product = $result['payload'][0];
keyExists('variations',$product,'Is the variations key set?');
hasAtLeast($product['variations'],1,"Has at least 1 variation?");
// The first variation size_attribute should be 'small'
$v1 = $product['variations'][0];
equal($v1['size_attribute'],'small',"Is the size_attribute set?");

// Now we want to edit the first variation

$old_name = $product['variations'][0]['name'];
$new_name = $old_name . " Edited";
echo " First variation name is: {$old_name}\n";

$product['variations'][0]['name'] = $new_name;

$result['payload'] = array($product);
$result['proc'] = 'set_products';
$result = curl_post($url,$result);

$result = json_decode($result,true); 
$product = $result['payload'][0];

// We make sure we get the product fresh from the db
$data = array(
  'action'      => 'woocommerce_json_api',
  'proc'        => 'get_products',
  'arguments'   => array(
    'token' => $token,
    'ids' => array( $product['id']),
  )
);
$result = curl_post($url,$data);
$result = json_decode($result,true); 

$product = $result['payload'][0];

notEqual($old_name, $product['variations'][0]['name'], "Was the variation name edited?");

// Now we edit it back

$product['variations'][0]['name'] = $old_name;

$result['payload'] = array($product);
$result['proc'] = 'set_products';
$result = curl_post($url,$result);

$result = json_decode($result,true); 
$product = $result['payload'][0];

// We make sure we get the product fresh from the db
$data = array(
  'action'      => 'woocommerce_json_api',
  'proc'        => 'get_products',
  'arguments'   => array(
    'token' => $token,
    'ids' => array( $product['id']),
  ),
  'model_filters' => array(
    /*
     * We need to edit a dynamic attribute, so we have
     * to let the model layer know it should load up 
     * a specific attribute.
     * 
     * In this case, we have an attribute called Size,
     * WooCom will save this in the db as: attribute_size
     */
    'WCAPI_product_meta_attributes_table' => array(
      'size_attribute' => array(
       'name' => 'attribute_size',          
       'type' => 'string', 
       'values' => array(
        'small',
        'medium',
        'large',
       ),
       'sizehint' => 2
     ),
    )

  ),
);
$result = curl_post($url,$data);
$result = json_decode($result,true); 
$product = $result['payload'][0];
$v1 = $product['variations'][0];
equal($v1['size_attribute'],'small',"Is the size_attribute set?");
equal($old_name, $product['variations'][0]['name'],"Did we restore the variation name?");