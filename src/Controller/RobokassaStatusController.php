<?php
namespace Drupal\robokassa\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RobokassaStatusController extends ControllerBase {

  public function statusPage()
  {
    if (\Drupal::currentUser()->id() !== 0) {
      $path = Url::fromRoute(
        'profile.user_page.single',
        [
          'profile_type' => 'orders',
          'user'         => \Drupal::currentUser()->id(),
        ]
      )->toString();
      $response = new RedirectResponse($path);

      return $response;
    }
    global $base_url;
    $response = new RedirectResponse($base_url);

    return $response;
  }

  public function checkAccess()
  {
    return AccessResult::allowedIf(true);
  }
}
