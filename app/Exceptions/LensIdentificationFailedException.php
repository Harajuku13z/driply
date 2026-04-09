<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Photo non exploitable : vision n’a pas produit de requête Shopping utilisable.
 */
class LensIdentificationFailedException extends \RuntimeException {}
