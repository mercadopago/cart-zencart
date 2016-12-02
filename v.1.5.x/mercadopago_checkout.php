<?php

require('includes/modules/payment/mercadopago.php');
require('includes/application_top.php');
require($template->get_template_dir('html_header.php',DIR_WS_TEMPLATE, $current_page_base,'common'). '/html_header.php');
require($template->get_template_dir('tpl_header.php',DIR_WS_TEMPLATE, $current_page_base,'common'). '/tpl_header.php');


if(isset($_REQUEST['init_point']) && $_REQUEST['init_point'] != ''){
  $mp = new mercadopago();
  $data = $mp->_getSponsorAndSite();
  $banner = $mp->_getBannerBySiteId($data['site_id']);
  $init_point = $_REQUEST['init_point'];
  $html = '';
?>

<script type="text/javascript" src="https://www.mercadopago.com/org-img/jsapi/mptools/buttons/render.js"></script>

<div id="mp-box">
  <img src="<?php echo $banner; ?>">
  <br/>
  <br/>

  <?php
    switch (MODULE_PAYMENT_MERCADOPAGO_TYPE_CHECKOUT) {
      case 'Redirect':
          $html .= '<script type="text/javascript">';
          $html .= '	$MPC.openCheckout ({';
          $html .= '		url: "'. $init_point . '",';
          $html .= '		mode: "redirect",';
          $html .= '		onreturn: function(data) {';
          $html .= '		}';
          $html .= '});';
          $html .= '</script>';
          break;
        case 'Lightbox':
          $html .= '<a href="'. $init_point .'" name="MP-Checkout" class="blue-M-Rn" mp-mode="modal">Pagar</a>';
          $html .= '<script type="text/javascript">';
          $html .= '	$MPC.openCheckout ({';
          $html .= '		url: "'. $init_point . '",';
          $html .= '		mode: "modal",';
          $html .= '		onreturn: function(data) {';
          $html .= '		}';
          $html .= '});';
          $html .= '</script>';
          break;
        case 'Iframe':
        default:
          $html .= '<iframe src="' . $init_point . '" name="MP-Checkout" width="800" height="600" frameborder="0"></iframe>';
          break;
      }

      echo $html;
  ?>
</div>
<?php
}
?>

<script src="https://secure.mlstatic.com/modules/javascript/analytics.js"></script>
<script>
  var MA = ModuleAnalytics;
  MA.setToken('<?php echo MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID; ?>');
  MA.setPaymentType('basic');
  MA.setCheckoutType('basic');
  MA.put();
</script>

<style>
#mp-box{
  margin: 50px auto;
  text-align: center;
}
</style>

<?php
require($template->get_template_dir('tpl_footer.php',DIR_WS_TEMPLATE, $current_page_base,'common'). '/tpl_footer.php');
require(DIR_WS_INCLUDES . 'application_bottom.php');
?>
