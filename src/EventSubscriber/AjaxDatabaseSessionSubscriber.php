<?php
namespace Lp\MatterhornImport\EventSubscriber;

use Lp\MatterhornImport\Database\AjaxDatabaseSessionGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AjaxDatabaseSessionSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'matterhorn_import_ajax';

    public function __construct(private AjaxDatabaseSessionGuard $databaseSession)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // RouterListener resolves _route at priority 32; the security firewall is
        // later. Priority 20 lets us repair/tune Doctrine before BO auth queries.
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route', '');
        if ($route === '' || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        $this->databaseSession->prepareDoctrine();
    }
}
