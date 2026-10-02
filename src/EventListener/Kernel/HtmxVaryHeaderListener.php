<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

namespace Markocupic\SacEventBlogBundle\EventListener\Kernel;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The event blog list and reader elements answer HTMX requests (header "HX-Request")
 * with an HTML fragment instead of the full page under the same URL.
 * The "Vary" header makes sure, that the HTTP cache keeps both responses apart.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
class HtmxVaryHeaderListener
{
    public function __construct(
        private readonly ScopeMatcher $scopeMatcher,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$this->scopeMatcher->isFrontendMainRequest($event)) {
            return;
        }

        // HX-Target: htmx also sends HX-Request when it restores the history (full page, without HX-Target)
        $event->getResponse()->setVary(['HX-Request', 'HX-Target'], false);
    }
}
