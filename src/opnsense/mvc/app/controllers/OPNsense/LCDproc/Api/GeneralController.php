<?php

/**
 *    Copyright (C) 2024 OPNsense LCDproc Plugin
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\LCDproc\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class GeneralController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'lcdproc';
    protected static $internalModelClass = '\OPNsense\LCDproc\LCDproc';

    /**
     * Retrieve model settings.
     *
     * The model contains two sections (general and screens) under the
     * 'lcdproc' model name.  The JavaScript form helpers (setFormData)
     * expect the JSON root keys to match the first segment of each form
     * field ID (e.g. "general.enabled" → data['general']['enabled']).
     * We therefore return the sections directly at the root level.
     */
    public function getAction()
    {
        $result = [];
        if ($this->request->isGet()) {
            $nodes = $this->getModel()->getNodes();
            $result['general'] = $nodes['general'] ?? [];
            $result['screens'] = $nodes['screens'] ?? [];
        }
        return $result;
    }

    /**
     * Update model settings.
     *
     * The JavaScript helper (getFormData / saveFormToEndpoint) posts data
     * keyed by section name (e.g. {"general": {"enabled": "1", ...}}).
     * The parent setAction() would look for $_POST['lcdproc'] which
     * does not exist, so we handle both sections explicitly.
     */
    public function setAction()
    {
        $result = ['result' => 'failed'];
        if ($this->request->isPost()) {
            \OPNsense\Core\Config::getInstance()->lock();
            $mdl = $this->getModel();
            $update = [];
            foreach (['general', 'screens'] as $section) {
                $sectionData = $this->request->getPost($section);
                if (is_array($sectionData)) {
                    $update[$section] = $sectionData;
                }
            }
            $mdl->setNodes($update);
            $result = $this->validate();
            if (empty($result['result'])) {
                $this->setActionHook();
                return $this->save(false, true);
            }
        }
        return $result;
    }
}
