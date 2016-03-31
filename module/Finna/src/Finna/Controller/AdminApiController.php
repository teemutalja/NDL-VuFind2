<?php
/**
 * Admin Api Controller
 *
 * PHP Version 5
 *
 * Copyright (C) The National Library of Finland 2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.    See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA    02111-1307    USA
 *
 * @category VuFind
 * @package  Controller
 * @author   Riikka Kalliomäki <riikka.kalliomaki@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
namespace Finna\Controller;

/**
 * Provides web api for different admin tasks.
 *
 * @category VuFind
 * @package  Controller
 * @author   Riikka Kalliomäki <riikka.kalliomaki@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
class AdminApiController extends \VuFind\Controller\AbstractBase
    implements FinnaApiInterface
{
    use FinnaApiTrait;

    /**
     * Clears the view's cache.
     *
     * @return \Zend\Http\Response
     */
    public function clearCacheAction()
    {
        $this->disableSessionWrites();
        $this->determineOutputMode();

        $auth = $this->serviceLocator->get('ZfcRbac\Service\AuthorizationService');

        if (!$auth->isGranted('finna.cache')) {
            return $this->output([], self::STATUS_ERROR, 403, 'Permission denied');
        }

        $manager = $this->getServiceLocator()->get('VuFind\CacheManager');

        foreach ($manager->getCacheList() as $key) {
            if (in_array($key, ['cover', 'description'])) {
                continue;
            }

            $cache = $manager->getCache($key);
            $cache->flush();
        }

        return $this->output([], self::STATUS_OK);
    }
}
