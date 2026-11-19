<?php
/**
 * BarangayLink - Typed rule failure for complaint filing and parties (complaint revision Phase 5).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Thrown by _filing.php and _parties.php. Endpoints turn it into
 * json_error($problem->getMessage(), $problem->status). Anything else that is
 * thrown is a server failure (500, detail logged only).
 */

if (!class_exists('ComplaintProblem', false)) {
  final class ComplaintProblem extends RuntimeException {
    public int $status;
    /** Extra keys for the error body (Phase 6: hearing conflicts). Never personal data. */
    public array $extra;
    public function __construct(string $message, int $status = 422, array $extra = []) {
      parent::__construct($message);
      $this->status = $status;
      $this->extra = $extra;
    }
  }
}
