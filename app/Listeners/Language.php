<?php
/*
 * This file is part of AtroPIM.
 *
 * AtroPIM - Open Source PIM application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 * Website: https://atropim.com
 *
 * AtroPIM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroPIM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AtroPIM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "AtroPIM" word.
 *
 * This software is not allowed to be used in Russia and Belarus.
 */

declare(strict_types=1);

namespace Pim\Listeners;

use Espo\Core\EventManager\Event;
use Espo\Core\Utils\Util;
use Espo\Listeners\AbstractListener;

/**
 * Class Language
 */
class Language extends AbstractListener
{
    /**
     * @param Event $event
     */
    public function modify(Event $event)
    {
        $data = $event->getArgument('data');

        $languages = [];
        if (!empty($this->getConfig()->get('isMultilangActive'))) {
            $languages = $this->getConfig()->get('inputLanguageList', []);
        }

        foreach ($data as $l => $rows) {
            foreach ($languages as $language) {
                $camelCaseLocale = ucfirst(Util::toCamelCase(strtolower($language)));
                $data[$l]['ProductFamilyAttribute']['fields']["attributeName$camelCaseLocale"] = $data[$l]['Attribute']['fields']["name"] . ' / ' . $language;
            }
            foreach ($this->getMetadata()->get(['entityDefs', 'Product', 'fields'], []) as $fields => $fieldDefs) {
                if (!empty($fieldDefs['attributeId'])) {
                    $attributeName = $fieldDefs['attributeName'];
                    if (isset($fieldDefs[Util::toCamelCase('attribute_name_' . strtolower($l))])) {
                        $attributeName = $fieldDefs[Util::toCamelCase('attribute_name_' . strtolower($l))];
                    }
                    if (!empty($fieldDefs['multilangLocale'])) {
                        $attributeName .= ' / ' . $fieldDefs['multilangLocale'];
                    }
                    $data[$l]['Product']['fields'][$fields] = $attributeName;
                }
            }
        }

        $event->setArgument('data', $data);
    }
}
