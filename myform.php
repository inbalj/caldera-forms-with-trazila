<?php
/*
 * Plugin Name: Tranzila form customised
 * Description: Adds a tranzila to form
 * Version: 0.1
 * Created on Thu Nov 30 2017
 * Author: Inbal Jona
 * Copyright (c) Inbal jona
 * Text Domain: donfo
 * License: GPL-2.0+
 */


/**
* Gather all data from Caldera Forms submission as PHP array
*/

function enqueue_my_scripts() {
    wp_enqueue_script( 'jquery' );
    wp_register_script( 'my-form-js', plugins_url( 'js/script.js', __FILE__ ));
    wp_enqueue_script( 'my-form-js' );
    wp_register_style( 'my-form-style', plugins_url('css/style.css',__FILE__ ));
    wp_enqueue_style( 'my-form-style' );
}

add_action('wp_enqueue_scripts', 'enqueue_my_scripts');


add_action( 'caldera_forms_submit_complete', 'donfo_get_form_data',55);
function donfo_get_form_data($form){
    $form_id = 'CF51115149115d9'; // caldera form id
    $field_id = 'fld_2303149'; //token field
    $entry_id = null ;
    $success = false;
    //put form field data into an array $data
    if ($form['ID'] == $form_id){
        $data= array();
        // get entry id
        $entry_id = Caldera_Forms::get_field_data( '_entry_id', $form );

    // get fields data
    foreach( $form[ 'fields' ] as $field_id => $field){
        $data[ $field['slug'] ] = Caldera_Forms::get_field_data( $field_id, $form );
    }
    // if user selected credit card payment method
    if ($data['select_payment_method'] == 'credit'){
        //get a token for the credit card
        $token = donfo_create_token($data, $form, $entry_id);

        if ($token !=''){
            //if there is a token - make a tranaction
            $confirmationcode = donfo_transaction($data ,$token, $form_id, $entry_id);
            if ($confirmationcode != ''){
                // success !!! 
            }
        }
    }
    
    } // if ($form['ID']== $form_id

    return $form;
}

/**
* Send data from Caldera form to Tranzila to receive the token
*/
function donfo_create_token($data, $form, $entry_id){
    $tranzila_api_host = 'https://secure5.tranzila.com';
    $tranzila_api_path = '/cgi-bin/tranzila71u.cgi';
    $supplier = 'supplier-name'; // enter your supplier name
    $tranzilapw = 'tranzilapw'; //enter your tranzila pw
    $tranzilatoken = '';
    $field_id = 'fld_2303149'; //token field

    $expdate = str_replace("/", "", $data['expiration_date']);
    $expdate = preg_replace('/\s+/', '', $expdate);
	// Prepare transaction parameters>
	$query_parameters['supplier'] = $supplier;
  // $query_parameters['sum'] = $data['total_amount']; //Transaction sum
//	$query_parameters['currency'] = $data['currency']; //Type of currency 1 NIS, 2 USD, 978 EUR, 826 GBP, 392 JPY
	$query_parameters['ccno'] = $data['credit_card_number']; // Test card number = '12312312'
//    $query_parameters['expdate'] = $expdate ;// Card expiry date: mmyy ='0820'
    if($data['currency'] == 1){
  //     $query_parameters['myid'] = $data['id_number']; // ID number = '12312312'
    }
    else{
  //      $query_parameters['myid'] = '';
    }
 //   $query_parameters['cred_type'] = '1'; // This field specifies the type of transaction, 1 - normal transaction, 6 - credit, 8 - payments
	$query_parameters['TranzilaPW'] = $tranzilapw; // Token password if required
  //  $query_parameters['tranmode'] = 'V'; // Mode for verify transaction
    $query_parameters['TranzilaTK'] = 1;
	// Prepare query string
	$query_string = '';
    foreach ($query_parameters as $name => $value) {
        $query_string .= $name . '=' . $value . '&';
    }

    $query_string = substr($query_string, 0, -1); // Remove trailing '&'

    // Initiate CURL
    $cr = curl_init();

    curl_setopt($cr, CURLOPT_URL,$tranzila_api_host.$tranzila_api_path);
    curl_setopt($cr, CURLOPT_POST, 1);
    curl_setopt($cr, CURLOPT_FAILONERROR, true);
    curl_setopt($cr, CURLOPT_POSTFIELDS, $query_string);
    curl_setopt($cr, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($cr, CURLOPT_SSL_VERIFYPEER, 0);

    // Execute request
    $result = curl_exec($cr);
    $error = curl_error($cr);
    if (!empty($error)) {
        return ($error);
    }
    curl_close($cr);


    // Preparing associative array with response data
    $response_array = explode('&', $result);
    if (count($response_array) >= 1) {
        foreach ($response_array as $value) {
            $tmp = explode('=', $value);
            if (count($tmp) > 1) {
                $response_assoc[$tmp[0]] = $tmp[1];
            }
        }
    }
    // Analyze the result string
    if (!isset($response_assoc['TranzilaTK'])) {
        //getting a token was unsuccessful
        add_action('admin_notices', function() use ($response_assoc) {
            echo '<div class="error"><p>', esc_html($response_assoc), '</p></div>';
        });
        return $tranzilatoken;
    }
    else {
        $tranzilatoken = $response_assoc['TranzilaTK']; //TranzilaTK
       //submit tranzila token  value into the tranzilatoken field
        $entry = new Caldera_Forms_Entry( $form, $entry_id );
        //Get field object of field to edit
        $field_to_edit = $entry->get_field( $field_id );
        //Change fields value
        $field_to_edit->value = $tranzilatoken;
        //Put modified field back in entry
        $entry->add_field( $field_to_edit );
        //Save entry
        $entry->save();

    }
    return $tranzilatoken;
}

/**
* Send transaction data from Caldera form to Tranzila and recieve confirmation code.
*/
function donfo_transaction($data ,$token, $form, $entry_id){
    $tranzila_api_host = 'https://secure5.tranzila.com';
    $tranzila_api_path = '/cgi-bin/tranzila71u.cgi';
    $supplier = 'supplier-name'; // enter your supplier name
    $tranzilapw = 'tranzilapw'; //enter your tranzila pw
    $field_id = 'fld_7259702'; //confirmation field
    $tranzilaconfirmation = '';

    $expdate = str_replace("/", "", $data['expiration_date']);
    $expdate = preg_replace('/\s+/', '', $expdate);
    // Prepare transaction parameters>
    $query_parameters['supplier']   = $supplier;
    $query_parameters['sum']        = $data['total_amount'];       //Transaction sum
    $query_parameters['currency']   = $data['currency'];           //Type of currency 1 NIS, 2 USD, 978 EUR, 826 GBP, 392 JPY
    $query_parameters['ccno']       = $data['credit_card_number']; // Test card number = '12312312'
    $query_parameters['expdate']    = $expdate ;                   // Card expiry date: mmyy ='0820'
    if($data['currency'] == 1){
        $query_parameters['myid'] = $data['id_number']; // ID number = '12312312'
     }
     else{
         $query_parameters['myid'] = '';
     }
    $query_parameters['cred_type']  = '1';                         // This field specifies the type of transaction, 1 - normal transaction, 6 - credit, 8 - payments
    $query_parameters['TranzilaPW'] = $tranzilapw;                 // Token password if required
    $query_parameters['tranmode']   = 'A';                         // Mode for verify transaction
    $query_parameters['TranzilaTK'] = $token;
    /* if ($data['frequency']!= 1){ if there are payments
        //multiple payments
        $query_parameters['fpay'] = $data['total_amount'];
        $query_parameters['spay'] = $data['total_amount'];
        $query_parameters['npay'] = 12;
    } */
    // Prepare query string
    $query_string = '';
    foreach ($query_parameters as $name => $value) {
        $query_string .= $name . '=' . $value . '&';
    }

    $query_string = substr($query_string, 0, -1); // Remove trailing '&'

    // Initiate CURL
    $cr = curl_init();

    curl_setopt($cr, CURLOPT_URL,$tranzila_api_host.$tranzila_api_path);
    curl_setopt($cr, CURLOPT_POST, 1);
    curl_setopt($cr, CURLOPT_FAILONERROR, true);
    curl_setopt($cr, CURLOPT_POSTFIELDS, $query_string);
    curl_setopt($cr, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($cr, CURLOPT_SSL_VERIFYPEER, 0);

    // Execute request
    $result = curl_exec($cr);
    $error = curl_error($cr);

    if (!empty($error)) {
        die ($error);
    }
    curl_close($cr);

    // Preparing associative array with response data
    $response_array = explode('&', $result);
    $response_assoc = array();
    if (count($response_array) > 1) {
        foreach ($response_array as $value) {
            $tmp = explode('=', $value);
            if (count($tmp) > 1) {
                $response_assoc[$tmp[0]] = $tmp[1];
            }
        }
    }
    // Analyze the result string
    if (!isset($response_assoc['Response'])) {
        /**
         * When there is no 'Response' parameter it either means
         * that some pre-transaction error happened (like authentication
         * problems), in which case the result string will be in HTML format,
         * explaining the error, or the request was made for generate token only
         * (in this case the response string will contain only 'TranzilaTK'
         * parameter)
         */
    } else if ($response_assoc['Response'] !== '000') {
        // Any other than '000' code means transaction failure
        // (bad card, expiry, etc..)
    }
    else {
        $tranzilaconfirmation = $response_assoc['ConfirmationCode']; //ConfirmationCode
        $data['transaction_id'] = $tranzilaconfirmation;
        //submit ConfirmationCode  value into the transaction_id field
        $entry = new Caldera_Forms_Entry( $form, $entry_id );
        //Get field object of field to edit
        $field_to_edit = $entry->get_field( $field_id );
        //Change fields value
        $field_to_edit->value = $tranzilaconfirmation;
        //Put modified field back in entry
        $entry->add_field( $field_to_edit );
        //Save entry
        $entry->save();
        //success
    }
    return $tranzilaconfirmation;
}
