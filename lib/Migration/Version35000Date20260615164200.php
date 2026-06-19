<?php

declare(strict_types=1);

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version35000Date20260615164200 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('gopaperless_dpo_mobile_options')) {
			return $schema;
		}

		$table = $schema->getTable('gopaperless_dpo_mobile_options');

		/**
		 * Remove the uniqueness constraint from DPO mobile options.
		 *
		 * This table acts as a cache of DPO's GetMobilePaymentOptions response
		 * and is refreshed by replacing existing entries with the latest snapshot
		 * returned by DPO.
		 *
		 * A uniqueness constraint is inappropriate because DPO may return multiple
		 * providers sharing the same country and prefix combination, for example:
		 *
		 * KE + 254 → safaricomstkv2
		 * KE + 254 → airtelke
		 *
		 * UG + 256 → mobile_airtel_ug
		 * UG + 256 → mtnmobilemoney
		 *
		 * Additionally, DPO provider identifiers may change over time. Since this
		 * table is treated as a replaceable cache rather than a source of truth,
		 * uniqueness is enforced by the cache replacement strategy instead of a
		 * database constraint.
		 */
		if ($table->hasIndex('uniq_dpo_option')) {
			$table->dropIndex('uniq_dpo_option');
		}

		return $schema;
	}
}
