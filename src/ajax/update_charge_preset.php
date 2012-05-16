<?php
/* Osmium
 * Copyright (C) 2012 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require __DIR__.'/../../inc/root.php';
require __DIR__.'/../../inc/json_common.php';

if(!osmium_logged_in()) {
  die();
}

if(!isset($_GET['token']) || $_GET['token'] != osmium_tok()) {
  die();
}

$fit =& osmium_get_fit();

if($_GET['action'] == 'update') {
  $idx = intval($_GET['index']);
  $fit['charges'][$idx]['name'] = $_GET['name'];
  foreach(osmium_slottypes() as $type) {
    $i = 0;
    $fit['charges'][$idx][$type] = array();
    for($i = 0; $i < 16; ++$i) {
      if(!isset($_GET[$type.$i])) continue;
      $fit['charges'][$idx][$type][$i] = intval($_GET[$type.$i]);
    }
  }
} else if($_GET['action'] == 'delete') {
  $idx = intval($_GET['index']);
  unset($fit['charges'][$idx]);
  $fit['charges'] = array_values($fit['charges']); /* Reorder the numeric keys */
}
