<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DpoMobileOptionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gopaperless_dpo_mobile_options', DpoMobileOption::class);
	}

	/**
	 * Fetch all options for a given country
	 *
	 * @return DpoMobileOption[]
	 */
	public function findByCountry(string $country): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('o.*')
			->from($this->getTableName(), 'o')
			->where(
				$qb->expr()->eq(
					'o.country',
					$qb->createNamedParameter(strtolower($country))
				)
			);

		return $this->findEntities($qb);
	}


	/**
	 * Replace cached DPO options for affected countries.
	 *
	 * Existing entries for affected countries are removed before
	 * inserting the latest DPO response.
	 *
	 * We refresh entire country snapshots instead of performing
	 * per-provider upserts because DPO provider identifiers,
	 * instructions and available options may change over time.
	 *
	 * @param DpoMobileOption[] $options
	 */
	public function replaceSnapshot(array $options): void {
		if ($options === []) {
			return;
		}

		$countries = array_unique(
			array_map(
				static fn (DpoMobileOption $option): string
				=> strtolower($option->getCountry()),
				$options,
			)
		);

		$this->db->beginTransaction();

		try {

			$this->deleteByCountries($countries);

			foreach ($options as $option) {
				$this->insert($option);
			}

			$this->db->commit();
		} catch (\Throwable $e) {

			$this->db->rollBack();

			throw $e;
		}
	}


	/**
	 * Delete all cached options for the provided countries.
	 *
	 * @param string[] $countries
	 */
	public function deleteByCountries(array $countries): void {
		if ($countries === []) {
			return;
		}

		$countries = array_values(
			array_unique(
				array_map(
					'strtolower',
					$countries,
				)
			)
		);

		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->in(
					'country',
					$qb->createNamedParameter(
						$countries,
						IQueryBuilder::PARAM_STR_ARRAY,
					)
				)
			);

		$qb->executeStatement();
	}
}
