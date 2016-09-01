<?php

require('includes/modules/payment/mercadopago.php');
require('includes/application_top.php');


if(isset($_REQUEST['topic']) && $_REQUEST['topic'] = 'merchant_order'){

  $mp = new mercadopago();
  $status = $mp->_processIPNMerchantOrder();
  exit;
}
