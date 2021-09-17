<?php

namespace Drupal\robokassa\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Plugin\Field\FieldType\AdjustmentItemList;

class PaymentOffsiteForm extends BasePaymentOffsiteForm
{

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $payment->save();
    $order = $payment->getOrder();
    $totalPrice = $order->getTotalPrice();
    $userMail = $payment->getOrder()->getEmail();

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $data = [];

    $payment_gateway_configuration = $payment_gateway_plugin->getConfiguration();
    $receipt = [];
    $items = [];

    foreach ($order->getItems() as $key => $order_item) {
      $unitPrice = $order_item->getUnitPrice();
      $quantity = $order_item->getQuantity();
      $product_variation = $order_item->getPurchasedEntity();
      $title = $product_variation->getTitle();

      if ($payment_gateway_configuration['country'] == 'RU') {
        $items[] = [
          'name' => $title,
          'sum' => number_format($unitPrice->getNumber(), 2, '.', '') * $quantity,
          'quantity' => $quantity,
          'payment_method' => $payment_gateway_configuration['payment_method'],
          'payment_object' => $payment_gateway_configuration['payment_object'],
          'tax' => $payment_gateway_configuration['tax'],
        ];
      } else {
        $items[] = [
          'name' => $title,
          'quantity' => $quantity,
          'sum' => number_format($unitPrice->getNumber(), 2, '.', '') * $quantity,
          'tax' => $payment_gateway_configuration['tax'],
        ];
      }
    }

    if ($payment_gateway_configuration['country'] == 'RU') {
      $redirect_url = 'https://auth.robokassa.ru/Merchant/Index.aspx';
    } else {
      $redirect_url = 'https://auth.robokassa.kz/Merchant/Index.aspx';
    }

    $form['#action'] = $redirect_url;
    $data["MerchantLogin"] = $payment_gateway_configuration['MrchLogin'];
    $data["OutSum"] = number_format($totalPrice->getNumber(), 2, '.', '');
    $data["InvId"] = $payment->getOrderId();
    $data["email"] = $userMail;
    $data['receipt'] = $receipt[] = \urlencode(json_encode(array(
      'sno' => $payment_gateway_configuration['sno'],
      'items' => $items
    )));
    $data["shp_label"] = 'drupal_official';
    // For test transactions.
    if ($payment->getPaymentGatewayMode() == 'test') {
      $data['IsTest'] = '1';
    }

    $signature_data = array(
      $data["MerchantLogin"],
      $data["OutSum"],
      $data["InvId"],
      $data['receipt'] = $receipt[] = \urlencode(json_encode(array(
        'sno' => $payment_gateway_configuration['sno'],
        'items' => $items
      ))),
      $payment_gateway_configuration['pass1'],
      'shp_label=' . "drupal_official",
    );

    // Calculate signature.
    $data['SignatureValue'] = hash($payment_gateway_configuration['hash_type'], implode(':', $signature_data));

    if (isset($payment->getOrder()->getData('robokassa')['IncCurrLabel'])) {
      $data['IncCurrLabel'] = $payment->getOrder()->getData('robokassa')['IncCurrLabel'];
    }

    $payment->save();


    return $this->buildRedirectForm($form, $form_state, $redirect_url, $data, self::REDIRECT_POST);
  }
}
