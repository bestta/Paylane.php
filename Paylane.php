<?php
/**
 * BoxBilling
 *
 * @copyright BoxBilling, Inc (http://www.boxbilling.com)
 * @license   Apache-2.0
 *
 * Copyright BoxBilling, Inc
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

class Payment_Adapter_Paylane implements \Box\InjectionAwareInterface
{
    private $config = array();

    protected $di;

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;
        
        if(!function_exists('curl_exec')) {
            throw new Exception('PHP Curl extension must be enabled in order to use Paylane gateway');
        }
        
        if(!$this->config['email']) {
            throw new Exception('Payment gateway "Paylane" is not configured properly. Please update configuration parameter "Paylane Email address" at "Configuration -> Payments".');
        }
    }
    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'   =>  true,
            'supports_subscriptions'     =>  true,
            'description'     =>  'Enter your Paylane email to start accepting payments by Paylane.',
            'form'  => array(
                'email' => array('text', array(
                            'label' => 'Paylane email address for payments',
                            'validators'=>array('EmailAddress'),
                    ),
                 ),
            ),
        );
    }
    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(array('id'=>$invoice_id));
        $costumer = $invoice['costumer'];
        
        $p = array(
            ':id'=>sprintf('%05s', $invoice['nr']), 
            ':serie'=>$invoice['serie'], 
            ':title'=>$invoice['lines'][0]['title']
        );
        $title = __('Payment for invoice :serie:id [:title]', $p);
        $number = $invoice['nr'];
        
        if($subscription) {
            
            $subs = $invoice['subscription'];
            
            $data = array();
            $data['item_name']          = $title;
            $data['item_number']        = $number;
            $data['no_shipping']        = '1';
            $data['no_note']            = '1'; // Do not prompt payers to include a note with their payments. Allowable values for Subscribe buttons:
            $data['currency_code']      = $invoice['currency'];
            $data['return_url']             = $this->config['return_url'];
            $data['cancel_url']      = $this->config['cancel_url'];
            $data['notify_url']         = $this->config['notify_url'];
            $data['business']           = $this->config['email'];
            $data['cmd']                = '_xclick-subscriptions';
            $data['rm']                 = '2';
            $data['invoice_id']         = $invoice['id'];
            // Recurrence info
            $data['a3']                 = $this->moneyFormat($invoice['total'], $invoice['currency']); // Regular subscription price.
            $data['p3']                 = $subs['cycle']; 
            /**
             * t3: Regular subscription units of duration. Allowable values:
             *  D – for days; allowable range for p3 is 1 to 90
             *  W – for weeks; allowable range for p3 is 1 to 52
             *  M – for months; allowable range for p3 is 1 to 24
             *  Y – for years; allowable range for p3 is 1 to 5
             */
            $data['t3']                 = $subs['unit'];
            $data['src']                = 1; //Recurring payments. Subscription payments recur unless subscribers cancel their subscriptions before the end of the current billing cycle or you limit the number of times that payments recur with the value that you specify for srt.
            $data['sra']                = 1; //Reattempt on failure. If a recurring payment fails, Paylane attempts to collect the payment two more times before canceling the subscription.
            $data['charset']            = 'UTF-8'; 
            //client data
            $data['address1']           = $costumer -> $address;
            $data['city']               = $costumer ['city'];
            $data['email']              = $costumer ['email'];
            $data['name']               = $costumer ['name'];
            $data['zip']                = $costumer ['zip'];
            $data['state']              = $costumer ['state'];
            $data['charset']            = "utf-8";
             
        } else {
            $data = array();
            
            // Merchants details
            $data['pay_to_email'] = $this->config['email'];
            $data['return_url'] = $this->config['return_url'];
            $data['cancel_url'] = $this->config['cancel_url'];
            $data['status_url'] = $this->config['notify_url'];
            $data['language'] = "EN";
            // Payments Details
            $data['transaction_id'] = $invoice['id'];
            $data['amount'] = $invoice['total'];
            $data['currency'] = $invoice['currency'];
            // Client details
            $data['pay_from_email'] = $costumer['email'];
            $data['address'] = $costumer['address'];
            $data['firstname'] = $costumer['first_name'];
            $data['lastname'] = $costumer['last_name'];
            $data['city'] = $costumer['city'];
            $data['zip'] = $costumer['zip'];
            $data['state'] = $costumer['state'];
        }
        
        if($this->config['test_mode']) {
            $url = 'https://your_login:your_password@direct.paylane.com/rest/cards/sale';
        } else {
            $url = 'https://www.Paylane.com/cgi-bin/webscr';
        }
        
        return $this->_generateForm($url, $data);
    }
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if(APPLICATION_ENV != 'testing' && !$this->_isIpnValid($data)) {
            throw new Exception('IPN is not valid');
        }
        
        $ipn = $data['post'];
        
        $tx = $api_admin->invoice_transaction_get(array('id'=>$id));
        
        if(!$tx['invoice_id']) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'invoice_id'=>$data['get']['bb_invoice_id']));
        }
        
        if(!$tx['type']) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'type'=>$ipn['txn_type']));
        }
        
        if(!$tx['txn_id']) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'txn_id'=>$ipn['txn_id']));
        }
        
        if(!$tx['txn_status']) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'txn_status'=>$ipn['payment_status']));
        }
        
        if(!$tx['amount']) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'amount'=>$ipn['mc_gross']));
        }
        
        if(!$tx['currency']) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'currency'=>$ipn['mc_currency']));
        }
        
        $invoice = $api_admin->invoice_get(array('id'=>$data['get']['bb_invoice_id']));
        $client_id = $invoice['client']['id'];
        
        switch ($ipn['txn_type']) {
            
            case 'web_accept':
            case 'subscr_payment':
                
                if($ipn['payment_status'] == 'Completed') {
                    $bd = array(
                        'id'            =>  $client_id,
                        'amount'        =>  $ipn['mc_gross'],
                        'description'   =>  'Paylane transaction '.$ipn['txn_id'],
                        'type'          =>  'Paylane',
                        'rel_id'        =>  $ipn['txn_id'],
                    );
                    $api_admin->client_balance_add_funds($bd);
                    $api_admin->invoice_batch_pay_with_credits(array('client_id'=>$client_id));
                }
                
                break;
            
            case 'subscr_signup':
                $sd = array(
                    'client_id'     =>  $client_id,
                    'gateway_id'    =>  $gateway_id,
                    'currency'      =>  $ipn['mc_currency'],
                    'sid'           =>  $ipn['subscr_id'],
                    'status'        =>  'active',
                    'period'        =>  str_replace(' ', '', $ipn['period3']),
                    'amount'        =>  $ipn['amount3'],
                    'rel_type'      =>  'invoice',
                    'rel_id'        =>  $invoice['id'],
                );
                $api_admin->invoice_subscription_create($sd);
                
                $t = array(
                    'id'            => $id, 
                    's_id'          => $sd['sid'],
                    's_period'      => $sd['period'],
                );
                $api_admin->invoice_transaction_update($t);
                break;
            case 'recurring_payment_suspended_due_to_max_failed_payment':
            case 'subscr_failed':
            case 'subscr_eot':
            case 'subscr_cancel':
                $s = $api_admin->invoice_subscription_get(array('sid'=>$ipn['subscr_id']));
                $api_admin->invoice_subscription_update(array('id'=>$s['id'], 'status'=>'canceled'));
                break;
            default:
                error_log('Unknown Paylane transaction '.$id);
                break;
        }
        
        if($ipn['payment_status'] == 'Refunded') {
            $refd = array(
                'id'    => $invoice['id'],
                'note'  => 'Paylane refund '.$ipn['parent_txn_id'],
            );
            $api_admin->invoice_refund($refd);
        }
        
        $d = array(
            'id'        => $id, 
            'error'     => '',
            'error_code'=> '',
            'status'    => 'processed',
            'updated_at'=> date('c'),
        );
        $api_admin->invoice_transaction_update($d);
    }
    private function _isIpnValid($data)
    {
        // use http_raw_post_data instead of post due to encoding
        parse_str($data['http_raw_post_data'], $post);
        $req = 'cmd=_notify-validate';
        foreach ($post as $key => $value) {
            $value = urlencode(stripslashes($value));
            $req .= "&$key=$value";
        }
        $ret = $this->download('https://www.Paylane.com/cgi-bin/webscr', $req);
        return $ret == 'VERIFIED';
    }
    private function moneyFormat($amount, $currency)
    {
        //HUF currency do not accept decimal values
        if($currency == 'HUF') {
            return number_format($amount, 0);
        }
        return number_format($amount, 2, '.', '');
    }
    private function download($url, $post_vars = false, $phd = array(), $contentType = 'application/x-www-form-urlencoded')
    {
        $post_contents = '';
        if ($post_vars) {
            if (is_array($post_vars)) {
                foreach($post_vars as $key => $val) {
                    $post_contents .= ($post_contents ? '&' : '').urlencode($key).'='.urlencode($val);
                }
            } else {
                $post_contents = $post_vars;
            }
        }
        $uinf = parse_url($url);
        $host = $uinf['host'];
        $path = $uinf['path'];
        $path .= (isset($uinf['query']) && $uinf['query']) ? ('?'.$uinf['query']) : '';
        $headers = Array(
            ($post_contents ? 'POST' : 'GET')." $path HTTP/1.1",
            "Host: $host",
        );
        if ($phd) {
            if (!is_array($phd)) {
                $headers[count($headers)] = $phd;
            } else {
                $next = count($headers);
                $count = count($phd);
                for ($i = 0; $i < $count; $i++) { $headers[$next + $i] = $phd[$i]; }
            }
        }
        if ($post_contents) {
            $headers[] = "Content-Type: $contentType";
            $headers[] = 'Content-Length: '.strlen($post_contents);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // Apply the XML to our curl call
        if ($post_contents) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_contents);
        }
        $data = curl_exec($ch);
        if (curl_errno($ch)) return false;
        curl_close($ch);
        return $data;
    }
    
    private function _generateForm($url, $data, $method = 'post')
    {
        $form  = '';
        $form .= '<form name="payment_form" action="'.$url.'" method="'.$method.'">' . PHP_EOL;
        foreach($data as $key => $value) {
            $form .= sprintf('<input type="hidden" name="%s" value="%s" />', $key, $value) . PHP_EOL;
        }
        $form .=  '<input class="bb-button bb-button-submit" type="submit" value="Pay with Paylane" id="payment_button"/>'.PHP_EOL;
        $form .=  '</form>' . PHP_EOL . PHP_EOL;

        if(isset($this->config['auto_redirect']) && $this->config['auto_redirect']) {
            $form .= sprintf('<h2>%s</h2>', __('Redirecting to Paylane.com'));
            $form .= "<script type='text/javascript'>$(document).ready(function(){    document.getElementById('payment_button').style.display = 'none';     document.forms['payment_form'].submit();});</script>";
        }
        return $form;
    }
}