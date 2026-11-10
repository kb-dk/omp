<?php

/**
 * @defgroup pages_notfound Not found page
 */

/**
 * @file pages/notfound/index.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup pages_notfound
 *
 * @brief KB: handle requests for the general "not found" page (/index/notfound).
 * See APP\core\Dispatcher::handle404().
 *
 */

return new APP\pages\notfound\NotFoundHandler();
