<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\File;

use DateTimeInterface;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\SignatureFlow;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\ResponseDefinitions;
use OCA\Libresign\Service\FileElementService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Sponsorship\DTO\PersistedSignerSponsorshipDTO;
use OCA\Libresign\Service\Sponsorship\SponsorshipContextBuilderService;
use OCP\AppFramework\Db\Entity;
use OCP\Files\File as NodeFile;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;

/**
 * @psalm-import-type LibresignVisibleElement from ResponseDefinitions
 * @psalm-import-type LibresignFileSummary from ResponseDefinitions
 * @psalm-import-type LibresignDetailedFile from ResponseDefinitions
 * @psalm-import-type LibresignDetailedFileResponse from ResponseDefinitions
 * @psalm-import-type LibresignFileListItem from ResponseDefinitions
 * @psalm-import-type LibresignPagination from ResponseDefinitions
 * @psalm-import-type LibresignSignerDetail from ResponseDefinitions
 * @psalm-import-type LibresignSignerSummary from ResponseDefinitions
 */
class FileListService {
	public function __construct(
		private SignRequestMapper $signRequestMapper,
		private IdentifyMethodService $identifyMethodService,
		private FileElementService $fileElementService,
		private FileMapper $fileMapper,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private IUserManager $userManager,
		private IRootFolder $root,
		private SponsorshipContextBuilderService $sponsorshipContextBuilderService,
	) {
	}

	/**
	 * @return array{data: list<LibresignFileSummary|LibresignDetailedFile>, pagination: LibresignPagination}
	 */
	public function listAssociatedFilesOfSignFlow(
		IUser $user,
		$page = null,
		$length = null,
		array $filter = [],
		array $sort = [],
		bool $details = false,
	): array {
		$page ??= 1;
		$length ??= (int)$this->appConfig->getValueInt(Application::APP_ID, 'length_of_page', 100);

		$return = $this->signRequestMapper->getFilesAssociatedFilesWithMe(
			$user,
			$filter,
			$page,
			$length,
			$sort,
		);

		$signers = $this->signRequestMapper->getByMultipleFileId(array_map(fn (File $file) => $file->getId(), $return['data']));
		$identifyMethods = $this->signRequestMapper->getIdentifyMethodsFromSigners($signers);
		if ($details) {
			$visibleElements = $this->signRequestMapper->getVisibleElementsFromSigners($signers);
			$return['data'] = $this->associateAllAndFormat($user, $return['data'], $signers, $identifyMethods, $visibleElements);
		} else {
			$return['data'] = $this->associateAllAndFormatSummary($user, $return['data'], $signers, $identifyMethods);
		}

		$return['pagination']->setRouteName('ocs.libresign.File.list');
		return [
			'data' => $return['data'],
			'pagination' => $return['pagination']->getPagination($page, $length, $filter),
		];
	}

	/**
	 * @param File[] $files
	 * @param SignRequest[] $signers
	 * @param array<int, array<string, Entity&IdentifyMethod>> $identifyMethods
	 * @return list<LibresignFileSummary>
	 */
	private function associateAllAndFormatSummary(
		IUser $user,
		array $files,
		array $signers,
		array $identifyMethods,
	): array {
		$formattedFiles = [];
		foreach ($files as $file) {
			$fileSigners = array_filter($signers, fn ($signer) => $signer->getFileId() === $file->getId());
			$formattedFiles[] = $this->formatSingleFileSummary($file, $fileSigners, $identifyMethods, $user);
		}
		return $formattedFiles;
	}

	public function formatSingleFile(IUser $user, File $file): array {
		$signRequests = $this->signRequestMapper->getByMultipleFileId([$file->getId()]);

		$persistedSigners = $this->sponsorshipContextBuilderService
			->buildSignersWithSponsorshipContext(
				$file,
				$signRequests,
			);

		$identifyMethods = $this->signRequestMapper->getIdentifyMethodsFromSigners($signRequests);
		$visibleElements = $this->signRequestMapper->getVisibleElementsFromSigners($signRequests);

		return $this->formatSingleFileData($file, $persistedSigners, $identifyMethods, $visibleElements, $user);
	}

	public function formatSingleFileForSignRequest(File $file, ?SignRequest $currentSignRequest = null): array {
		$signRequests = $this->signRequestMapper->getByMultipleFileId([$file->getId()]);

		$persistedSigners = $this->sponsorshipContextBuilderService
			->buildSignersWithSponsorshipContext(
				$file,
				$signRequests,
			);

		$identifyMethods = $this->signRequestMapper->getIdentifyMethodsFromSigners($signRequests);
		$visibleElements = $this->signRequestMapper->getVisibleElementsFromSigners($signRequests);

		return $this->formatSingleFileData(
			$file,
			$persistedSigners,
			$identifyMethods,
			$visibleElements,
			null,
			$currentSignRequest?->getId(),
		);
	}

	/**
	 * Format multiple envelope child files for a sign request with preloaded data.
	 * Avoids N+1 queries by reusing the provided sign request collection.
	 *
	 * @param File[] $childFiles
	 * @param SignRequest[] $childSignRequests
	 * @return list<array<string, mixed>>
	 */
	public function formatEnvelopeChildFilesForSignRequest(
		array $childFiles,
		array $childSignRequests,
		?SignRequest $currentSignRequest = null,
	): array {
		$identifyMethods = $this->signRequestMapper->getIdentifyMethodsFromSigners($childSignRequests);
		$visibleElements = $this->signRequestMapper->getVisibleElementsFromSigners($childSignRequests);

		$signRequestsByFileId = [];
		foreach ($childSignRequests as $signRequest) {
			$signRequestsByFileId[$signRequest->getFileId()][] = $signRequest;
		}
		$currentIdentifyKey = null;
		if ($currentSignRequest) {
			if (!isset($identifyMethods[$currentSignRequest->getId()])) {
				$currentIdentifyMethods = $this->signRequestMapper->getIdentifyMethodsFromSigners([$currentSignRequest]);
				$identifyMethods += $currentIdentifyMethods;
			}
			$currentIdentifyMethods = $identifyMethods[$currentSignRequest->getId()] ?? [];
			$currentIdentifyKey = $this->buildIdentifyKey($currentIdentifyMethods);
		}

		$formatted = [];
		foreach ($childFiles as $childFile) {
			$signers = $signRequestsByFileId[$childFile->getId()] ?? [];
			$meSignRequestId = null;
			if ($currentIdentifyKey !== null) {
				foreach ($signers as $signer) {
					$signerIdentifyKey = $this->buildIdentifyKey($identifyMethods[$signer->getId()] ?? []);
					if ($signerIdentifyKey === $currentIdentifyKey) {
						$meSignRequestId = $signer->getId();
						break;
					}
				}
			}

			$persistedSigners = $this->sponsorshipContextBuilderService->buildSignersWithSponsorshipContext($childFile, $signers);

			$formatted[] = $this->formatSingleFileData(
				$childFile,
				$persistedSigners,
				$identifyMethods,
				$visibleElements,
				null,
				$meSignRequestId,
			);
		}

		return $formatted;
	}

	/**
	 * @param File[] $files
	 * @param SignRequest[] $signRequests
	 * @param array<int, array<string, Entity&IdentifyMethod>> $identifyMethods
	 * @param array<int, FileElement[]> $visibleElements
	 * @return list<LibresignDetailedFile>
	 */
	private function associateAllAndFormat(
		IUser $user,
		array $files,
		array $signRequests,
		array $identifyMethods,
		array $visibleElements,
	): array {
		$formattedFiles = [];

		foreach ($files as $file) {
			$fileSignRequests = array_filter(
				$signRequests,
				static fn(SignRequest $signRequest): bool =>
				$signRequest->getFileId() === $file->getId(),
			);

			$persistedSigners =
				$this->sponsorshipContextBuilderService
				->buildSignersWithSponsorshipContext(
					$file,
					$fileSignRequests,
				);

			$fileSigners = array_filter(
				$persistedSigners,
				static fn(
					PersistedSignerSponsorshipDTO $signer,
				): bool =>
				$signer
					->getSignRequest()
					->getFileId() === $file->getId(),
			);

			$formattedFiles[] = $this->formatSingleFileData($file, $fileSigners, $identifyMethods, $visibleElements, $user, null);
		}
		return $formattedFiles;
	}

	/**
	 * Format a single file with its signers, identifyMethods and visibleElements.
	 * Core formatting used by list and single file operations.
	 *
	 * @param File $fileEntity
	 * @param PersistedSignerSponsorshipDTO[] $persistedSponsorshipSigners
	 * @param array<int, array<string, Entity&IdentifyMethod>> $identifyMethods
	 * @param array<int, FileElement[]> $visibleElements
	 * @param IUser|null $user
	 * @return LibresignDetailedFile
	 */
	private function formatSingleFileData(
		File $fileEntity,
		array $persistedSponsorshipSigners,
		array $identifyMethods,
		array $visibleElements,
		?IUser $user,
		?int $meSignRequestId = null,
	): array {
		$file = [
			'id' => $fileEntity->getId(),
			'nodeId' => $fileEntity->getNodeId(),
			'uuid' => $fileEntity->getUuid(),
			'name' => $fileEntity->getName(),
			'status' => $fileEntity->getStatus(),
			'metadata' => $fileEntity->getMetadata() ?? [],
			'docmdpLevel' => $fileEntity->getDocmdpLevel(),
			'createdAt' => $fileEntity->getCreatedAt(),
			'userId' => $fileEntity->getUserId(),
			'signatureFlow' => $fileEntity->getSignatureFlow(),
			'nodeType' => $fileEntity->getNodeType(),
		];
		$file['signatureFlow'] = SignatureFlow::fromNumeric($file['signatureFlow'])->value;
		$file['statusText'] = $this->fileMapper->getTextOfStatus($file['status']);
		$file['requested_by'] = [
			'userId' => $file['userId'],
			'displayName' => $this->userManager->get($file['userId'])?->getDisplayName(),
		];
		$file['created_at'] = $file['createdAt']->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
		$file['size'] = $this->getFileSize($fileEntity);

		if ($file['nodeType'] === 'envelope') {
			$file['filesCount'] = $file['metadata']['filesCount'] ?? 0;
			$file['files'] = [];
		} else {
			$signRequests = array_map(
				static fn(
					PersistedSignerSponsorshipDTO $persistedSigner,
				): SignRequest => $persistedSigner->getSignRequest(),
				$persistedSponsorshipSigners,
			);
			$file['filesCount'] = 1;
			$file['files'] = $this->formatChildFilesResponse([$fileEntity], $signRequests, $identifyMethods);
		}

		// Remove raw fields not needed in response
		unset($file['userId'], $file['createdAt']);

		$file['signers'] = [];
		foreach ($persistedSponsorshipSigners as $sponsoredSigner) {
			$signer = $sponsoredSigner->getSignRequest();
			if ($signer->getFileId() !== $fileEntity->getId()) {
				continue;
			}
			$signerData = $this->formatSignerData(
				$sponsoredSigner,
				$identifyMethods,
				$visibleElements,
				$file['metadata'],
				$user,
				$meSignRequestId,
			);
			$file['signers'][] = $signerData;
		}

		if ($user instanceof IUser && $meSignRequestId === null) {
			$this->resolveSignerMeFlags($file['signers'], $user);
		}

		// Prefer the sign UUID of a ready current-user signer. With duplicate
		// sign requests for the same identifier, a stale draft row can appear
		// before the active able-to-sign row, so the first match is not always
		// the one that can actually sign.
		foreach ($file['signers'] as $signerData) {
			if (
				!empty($signerData['me'])
				&& ($signerData['status'] ?? null) === SignRequestStatus::ABLE_TO_SIGN->value
				&& !empty($signerData['sign_uuid'])
			) {
				$file['signUuid'] = $signerData['sign_uuid'];
				break;
			}
		}
		if (!isset($file['signUuid'])) {
			foreach ($file['signers'] as $signerData) {
				if (!empty($signerData['me']) && !empty($signerData['sign_uuid'])) {
					$file['signUuid'] = $signerData['sign_uuid'];
					break;
				}
			}
		}
		if (isset($file['signUuid'])) {
			$file['url'] = $this->urlGenerator->linkToRoute('libresign.page.getPdfFile', ['uuid' => $file['signUuid']]);
		}

		$file['statusText'] = $this->fileMapper->getTextOfStatus((int)$file['status']);

		$file['signersCount'] = count($file['signers']);

		if (count($file['signers']) > 0) {
			usort($file['signers'], function ($a, $b) {
				$orderA = $a['signingOrder'] ?? PHP_INT_MAX;
				$orderB = $b['signingOrder'] ?? PHP_INT_MAX;
				return $orderA <=> $orderB ?: (($a['signRequestId'] ?? 0) <=> ($b['signRequestId'] ?? 0));
			});

			$file['visibleElements'] = [];
			foreach ($file['signers'] as $signer) {
				if (!empty($signer['visibleElements']) && is_array($signer['visibleElements'])) {
					$file['visibleElements'] = array_merge($file['visibleElements'], $signer['visibleElements']);
				}
			}
		} else {
			$file['visibleElements'] = [];
		}

		ksort($file);
		/** @var LibresignDetailedFile */
		return $file;
	}

	/**
	 * @param SignRequest[] $signers
	 * @param array<int, array<string, Entity&IdentifyMethod>> $identifyMethods
	 * @return LibresignFileSummary
	 */
	private function formatSingleFileSummary(
		File $fileEntity,
		array $signers,
		array $identifyMethods,
		IUser $user,
	): array {
		$metadata = $fileEntity->getMetadata() ?? [];
		$nodeType = $fileEntity->getNodeType();
		$filesCount = $nodeType === 'envelope'
			? max(0, (int)($metadata['filesCount'] ?? 0))
			: 1;

		$mySigners = array_values(array_filter($signers, fn (SignRequest $signer)
			=> $this->isCurrentUserSigner($identifyMethods[$signer->getId()] ?? [], $user),
		));
		$pendingSigners = array_values(array_filter($signers, fn (SignRequest $signer) => $signer->getSigned() === null));
		$isOrderedNumeric = SignatureFlow::fromNumeric($fileEntity->getSignatureFlow())->value === SignatureFlow::ORDERED_NUMERIC->value;
		$minOrder = empty($pendingSigners)
			? null
			: min(array_map(fn (SignRequest $signer) => $signer->getSigningOrder() ?: 1, $pendingSigners));

		$hasAbleSigner = array_filter($mySigners, fn (SignRequest $signer) => $signer->getStatus() === SignRequestStatus::ABLE_TO_SIGN->value);
		$canSign = $fileEntity->getStatus() > 0
			&& !empty($mySigners)
			&& !empty($pendingSigners)
			&& !empty($hasAbleSigner)
			&& !array_filter($mySigners, fn (SignRequest $signer) => $signer->getSigned() !== null)
			&& (!$isOrderedNumeric || array_filter($mySigners, fn (SignRequest $signer) => ($signer->getSigningOrder() ?: 1) === $minOrder));

		$signUuid = null;
		foreach ($mySigners as $signer) {
			if ($signer->getUuid() !== '' && $signer->getStatus() === SignRequestStatus::ABLE_TO_SIGN->value) {
				$signUuid = $signer->getUuid();
				break;
			}
		}
		if ($signUuid === null) {
			foreach ($mySigners as $signer) {
				if ($signer->getUuid() !== '' && $signer->getSigned() === null) {
					$signUuid = $signer->getUuid();
					break;
				}
			}
		}
		if ($signUuid === null) {
			foreach ($mySigners as $signer) {
				if ($signer->getUuid() !== '') {
					$signUuid = $signer->getUuid();
					break;
				}
			}
		}

		/** @var LibresignFileSummary */
		return [
			'id' => $fileEntity->getId(),
			'nodeId' => $fileEntity->getNodeId(),
			'uuid' => $fileEntity->getUuid(),
			'name' => $fileEntity->getName(),
			'status' => $fileEntity->getStatus(),
			'statusText' => $this->fileMapper->getTextOfStatus($fileEntity->getStatus()),
			'nodeType' => $nodeType,
			'created_at' => $fileEntity->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
			'signUuid' => $signUuid,
			'metadata' => $metadata,
			'docmdpLevel' => $fileEntity->getDocmdpLevel(),
			'signatureFlow' => SignatureFlow::fromNumeric($fileEntity->getSignatureFlow())->value,
			'signersCount' => count($signers),
			'signers' => [],
			'requested_by' => [
				'userId' => $fileEntity->getUserId(),
				'displayName' => $this->userManager->get($fileEntity->getUserId())?->getDisplayName(),
			],
			'filesCount' => $filesCount,
			'canSign' => $canSign,
		];
	}

	/**
	 * Format a single signer with its identify methods and visible elements
	 *
	 * @param PersistedSignerSponsorshipDTO $persistedSignerSponsorshipDTO
	 * @param array<int, array<string, Entity&IdentifyMethod>> $identifyMethods
	 * @param array<int, FileElement[]> $visibleElements
	 * @param array $metadata
	 * @param IUser $user
	 * @return LibresignSignerDetail
	 */
	private function formatSignerData(
		PersistedSignerSponsorshipDTO $persistedSignerSponsorshipDTO,
		array $identifyMethods,
		array $visibleElements,
		array $metadata,
		?IUser $user,
		?int $meSignRequestId = null,
	): array {
		$signer = $persistedSignerSponsorshipDTO->getSignRequest();
		$identifyMethodsOfSigner = $identifyMethods[$signer->getId()] ?? [];
		$resolvedDisplayName = $this->resolveSignerDisplayName($signer, $identifyMethodsOfSigner);
		$me = false;
		if ($meSignRequestId !== null) {
			$me = $signer->getId() === $meSignRequestId;
		} elseif ($user) {
			$me = array_reduce($identifyMethodsOfSigner, function (bool $carry, IdentifyMethod $identifyMethod) use ($user): bool {
				if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_ACCOUNT) {
					return $user->getUID() === $identifyMethod->getIdentifierValue();
				}
				if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_EMAIL && $user->getEMailAddress()) {
					return $user->getEMailAddress() === $identifyMethod->getIdentifierValue();
				}
				return $carry;
			}, false);
		}
		/** @var LibresignSignerDetail */
		$data = [
			'email' => array_reduce($identifyMethodsOfSigner, function (string $carry, IdentifyMethod $identifyMethod): string {
				if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_EMAIL) {
					return $identifyMethod->getIdentifierValue();
				}
				if (filter_var($identifyMethod->getIdentifierValue(), FILTER_VALIDATE_EMAIL)) {
					return $identifyMethod->getIdentifierValue();
				}
				return $carry;
			}, ''),
			'description' => $signer->getDescription(),
			'displayName' => $resolvedDisplayName,
			'request_sign_date' => $signer->getCreatedAt()->format(DateTimeInterface::ATOM),
			'signed' => null,
			'signRequestId' => $signer->getId(),
			'signingOrder' => $signer->getSigningOrder(),
			'status' => $signer->getStatus(),
			'statusText' => $this->signRequestMapper->getTextOfSignerStatus($signer->getStatus()),
			'me' => $me,
			'visibleElements' => isset($visibleElements[$signer->getId()])
				? $this->fileElementService->formatVisibleElements(
					$visibleElements[$signer->getId()],
					$metadata,
				)
				: [],
			'identifyMethods' => array_map(fn (IdentifyMethod $identifyMethod): array => [
				'method' => $identifyMethod->getIdentifierKey(),
				'value' => $identifyMethod->getIdentifierValue(),
				'mandatory' => $identifyMethod->getMandatory(),
			], array_values($identifyMethodsOfSigner)),
			'sponsorship' => $persistedSignerSponsorshipDTO->getSponsorship()->toArray(),
		];

		if ($data['me'] && !empty($identifyMethodsOfSigner)) {
			$temp = array_map(function (IdentifyMethod $identifyMethodEntity) use ($signer): array {
				$this->identifyMethodService->setCurrentIdentifyMethod($identifyMethodEntity);
				$identifyMethod = $this->identifyMethodService
					->setIsRequest(false)
					->getInstanceOfIdentifyMethod(
						$identifyMethodEntity->getIdentifierKey(),
						$identifyMethodEntity->getIdentifierValue(),
					);
				$signatureMethods = $identifyMethod->getSignatureMethods();
				$return = [];
				foreach ($signatureMethods as $signatureMethod) {
					if (!$signatureMethod->isEnabled()) {
						continue;
					}
					$signatureMethod->setEntity($identifyMethod->getEntity());
					$return[$signatureMethod->getName()] = $signatureMethod->toArray();
				}
				return $return;
			}, array_values($identifyMethodsOfSigner));
			$data['signatureMethods'] = [];
			foreach ($temp as $methods) {
				$data['signatureMethods'] = array_merge($data['signatureMethods'], $methods);
			}
			$data['sign_uuid'] = $signer->getUuid();
		}

		if ($signer->getSigned()) {
			$data['signed'] = $signer->getSigned()->format(DateTimeInterface::ATOM);
		}
		ksort($data);
		return $data;
	}

	/**
	 * Format signer data without user context
	 * Used when $user is null to still include basic signer information
	 * @param PersistedSignerSponsorshipDTO $persistedSignerSponsorshipDTO
	 * @param array<int, array<string, Entity&IdentifyMethod>> $identifyMethods
	 * @param array<int, FileElement[]> $visibleElements
	 * @return array
	 */
	private function formatSignerDataBasic(
		PersistedSignerSponsorshipDTO $persistedSignerSponsorshipDTO,
		array $identifyMethods,
		array $visibleElements,
	): array {
		$signer = $persistedSignerSponsorshipDTO->getSignRequest();
		$identifyMethodsOfSigner = $identifyMethods[$signer->getId()] ?? [];
		$resolvedDisplayName = $this->resolveSignerDisplayName($signer, $identifyMethodsOfSigner);
		/** @var LibresignSignerDetail */
		$data = [
			'email' => array_reduce($identifyMethodsOfSigner, function (string $carry, IdentifyMethod $identifyMethod): string {
				if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_EMAIL) {
					return $identifyMethod->getIdentifierValue();
				}
				if (filter_var($identifyMethod->getIdentifierValue(), FILTER_VALIDATE_EMAIL)) {
					return $identifyMethod->getIdentifierValue();
				}
				return $carry;
			}, ''),
			'description' => $signer->getDescription(),
			'displayName' => $resolvedDisplayName,
			'request_sign_date' => $signer->getCreatedAt()->format(DateTimeInterface::ATOM),
			'signed' => null,
			'signRequestId' => $signer->getId(),
			'signingOrder' => $signer->getSigningOrder(),
			'status' => $signer->getStatus(),
			'statusText' => $this->signRequestMapper->getTextOfSignerStatus($signer->getStatus()),
			'me' => false,
			'visibleElements' => isset($visibleElements[$signer->getId()])
				? $this->fileElementService->formatVisibleElements(
					$visibleElements[$signer->getId()],
					[],
				)
				: [],
			'identifyMethods' => array_map(fn (IdentifyMethod $identifyMethod): array => [
				'method' => $identifyMethod->getIdentifierKey(),
				'value' => $identifyMethod->getIdentifierValue(),
				'mandatory' => $identifyMethod->getMandatory(),
			], array_values($identifyMethodsOfSigner)),
			'sponsorship' => $persistedSignerSponsorshipDTO->getSponsorship()->toArray(),
		];

		if ($signer->getSigned()) {
			$data['signed'] = $signer->getSigned()->format(DateTimeInterface::ATOM);
		}
		ksort($data);
		return $data;
	}

	/**
	 * Prefer the sign request display name, with safe fallbacks from identify methods.
	 *
	 * @param SignRequest $signer
	 * @param IdentifyMethod[] $identifyMethodsOfSigner
	 */
	private function resolveSignerDisplayName(SignRequest $signer, array $identifyMethodsOfSigner): string {
		$displayName = $signer->getDisplayName();
		foreach ($identifyMethodsOfSigner as $identifyMethod) {
			if ($identifyMethod->getIdentifierKey() !== IdentifyMethodService::IDENTIFY_ACCOUNT) {
				continue;
			}
			$identifierValue = $identifyMethod->getIdentifierValue();
			if ($displayName === '' || $displayName === $identifierValue) {
				$user = $this->userManager->get($identifierValue);
				if ($user) {
					return $user->getDisplayName();
				}
			}
			if ($displayName !== '') {
				return $displayName;
			}
		}
		if ($displayName !== '') {
			return $displayName;
		}

		foreach ($identifyMethodsOfSigner as $identifyMethod) {
			if (!$identifyMethod->getMandatory()) {
				continue;
			}
			if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_ACCOUNT) {
				$user = $this->userManager->get($identifyMethod->getIdentifierValue());
				if ($user) {
					return $user->getDisplayName();
				}
			}
			return $identifyMethod->getIdentifierValue();
		}

		return '';
	}

	/**
	 * @param array<int|string, IdentifyMethod> $identifyMethodsOfSigner
	 */
	private function isCurrentUserSigner(array $identifyMethodsOfSigner, IUser $user): bool {
		return array_reduce($identifyMethodsOfSigner, function (bool $carry, IdentifyMethod $identifyMethod) use ($user): bool {
			if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_ACCOUNT) {
				return $user->getUID() === $identifyMethod->getIdentifierValue();
			}
			if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_EMAIL && $user->getEMailAddress()) {
				return $user->getEMailAddress() === $identifyMethod->getIdentifierValue();
			}
			return $carry;
		}, false);
	}

	/**
	 * When more than one signer matches the current user (for example because
	 * two accounts share the same email), keep only one as "me". Prefer an
	 * account identifier that matches the user's UID or email, then an email
	 * match, and finally the signer that is still able to sign.
	 *
	 * @param list<LibresignSignerDetail> $signers
	 */
	private function resolveSignerMeFlags(array &$signers, IUser $user): void {
		$meIndexes = [];
		foreach ($signers as $index => $signer) {
			if (!empty($signer['me'])) {
				$meIndexes[] = $index;
			}
		}
		if (count($meIndexes) <= 1) {
			return;
		}

		$scoreByIndex = [];
		foreach ($meIndexes as $index) {
			$signer = $signers[$index];
			$score = 0;
			foreach ($signer['identifyMethods'] ?? [] as $identifyMethod) {
				$method = $identifyMethod['method'] ?? '';
				$value = $identifyMethod['value'] ?? '';
				if ($method === IdentifyMethodService::IDENTIFY_ACCOUNT) {
					if ($value === $user->getUID()) {
						$score = max($score, 3);
					} elseif ($value === $user->getEMailAddress()) {
						$score = max($score, 2);
					}
				} elseif ($method === IdentifyMethodService::IDENTIFY_EMAIL && $value === $user->getEMailAddress()) {
					$score = max($score, 1);
				}
			}
			$scoreByIndex[$index] = $score;
		}

		$maxScore = max($scoreByIndex);
		$candidates = array_keys(array_filter($scoreByIndex, fn (int $score): bool => $score === $maxScore));

		$bestIndex = null;
		foreach ($candidates as $index) {
			if (($signers[$index]['status'] ?? null) === SignRequestStatus::ABLE_TO_SIGN->value) {
				$bestIndex = $index;
				break;
			}
		}
		if ($bestIndex === null) {
			$bestIndex = $candidates[0] ?? $meIndexes[0];
		}

		foreach ($meIndexes as $index) {
			if ($index === $bestIndex) {
				continue;
			}
			$signers[$index]['me'] = false;
			unset($signers[$index]['sign_uuid'], $signers[$index]['signatureMethods']);
		}
	}

	/**
	 * Format file response with child files for envelopes.
	 * Used by controllers to format main entity with its children.
	 *
	 * @param File $mainEntity
	 * @param File[] $childFiles
	 * @return LibresignDetailedFileResponse Complete formatted response with metadata, signers, and child files
	 * @psalm-suppress MoreSpecificReturnType
	 */
	/**
	 * Format file with children for response
	 *
	 * @param File $mainEntity
	 * @param File[] $childFiles
	 * @param IUser|null $user Optional user for formatting signers
	 * @return LibresignDetailedFileResponse
	 * @psalm-suppress MoreSpecificReturnType
	 */
	public function formatFileWithChildren(File $mainEntity, array $childFiles, ?IUser $user = null): array {
		$metadata = $mainEntity->getMetadata() ?? [];

		$signRequestEntities = $this->signRequestMapper->getByFileId($mainEntity->getId());

		$persistedSigners = $this->sponsorshipContextBuilderService
			->buildSignersWithSponsorshipContext(
				$mainEntity,
				$signRequestEntities,
			);

		$identifyMethods = $this->signRequestMapper->getIdentifyMethodsFromSigners($signRequestEntities);
		$childContext = $mainEntity->getNodeType() === 'envelope' && !empty($childFiles)
			? $this->getEnvelopeChildContext($childFiles)
			: null;
		$visibleElementsData = $mainEntity->getNodeType() === 'envelope'
			? []
			: $this->signRequestMapper->getVisibleElementsFromSigners($signRequestEntities);

		$signers = [];
		$signUuid = null;
		foreach ($persistedSigners as $signer) {
			if ($user) {
				$signerData = $this->formatSignerData($signer, $identifyMethods, $visibleElementsData, $metadata, $user);
				$signers[] = $signerData;

				if ($signUuid === null && !empty($signerData['me']) && isset($signerData['sign_uuid'])) {
					$signUuid = $signerData['sign_uuid'];
				}
			} else {
				$signers[] = $this->formatSignerDataBasic($signer, $identifyMethods, $visibleElementsData);
			}
		}

		if ($user instanceof IUser) {
			$this->resolveSignerMeFlags($signers, $user);
			// Refresh signUuid after resolving me flags
			foreach ($signers as $signerData) {
				if ($signUuid === null && !empty($signerData['me']) && isset($signerData['sign_uuid'])) {
					$signUuid = $signerData['sign_uuid'];
					break;
				}
			}
		}

		$rawFilesCount = $metadata['filesCount'] ?? null;
		$filesCount = is_numeric($rawFilesCount) ? (int)$rawFilesCount : count($childFiles);
		$filesCount = max(0, $filesCount);

		/** @var LibresignDetailedFileResponse */
		$response = [
			'message' => $this->l10n->t('Success'),
			'id' => $mainEntity->getId(),
			'nodeId' => $mainEntity->getNodeId(),
			'uuid' => $mainEntity->getUuid(),
			'name' => $mainEntity->getName(),
			'status' => $mainEntity->getStatus(),
			'statusText' => $this->fileMapper->getTextOfStatus($mainEntity->getStatus()),
			'nodeType' => $mainEntity->getNodeType(),
			'created_at' => $mainEntity->getCreatedAt()->format(\DateTimeInterface::ATOM),
			'metadata' => $metadata,
			'docmdpLevel' => $mainEntity->getDocmdpLevel(),
			'signatureFlow' => SignatureFlow::fromNumeric($mainEntity->getSignatureFlow())->value,
			'signers' => $signers,
			'signersCount' => count($signers),
			'requested_by' => [
				'userId' => $mainEntity->getUserId(),
				'displayName' => $this->userManager->get($mainEntity->getUserId())?->getDisplayName() ?? $mainEntity->getUserId(),
			],
		];

		if ($mainEntity->getNodeType() === 'envelope' && $user && !empty($childFiles) && count($signers) > 0) {
			$signers = $this->applyEnvelopeVisibleElementsByKey(
				$signers,
				$identifyMethods,
				$childContext['identifyMethods'] ?? [],
				$childContext['visibleElements'] ?? [],
				$childContext['metadataByFileId'] ?? [],
			);
			$response['signers'] = $signers;
		}

		$response['visibleElements'] = $this->collectVisibleElementsFromSigners($signers);

		if ($signUuid !== null) {
			$response['signUuid'] = $signUuid;
			$response['url'] = $this->urlGenerator->linkToRoute('libresign.page.getPdfFile', ['uuid' => $signUuid]);
		}

		if ($mainEntity->getNodeType() === 'envelope') {
			$response['filesCount'] = $filesCount;
			$response['files'] = $this->formatChildFilesResponse(
				$childFiles,
				$childContext['signers'] ?? null,
				$childContext['identifyMethods'] ?? null,
			);
			$response['size'] = array_sum(array_map(
				static fn (array $file): int => (int)$file['size'],
				$response['files'],
			));
		} else {
			$response['filesCount'] = 1;
			$response['files'] = $this->formatChildFilesResponse([$mainEntity], $signRequestEntities, $identifyMethods);
			$response['size'] = (int)$response['files'][0]['size'];
		}

		/** @psalm-suppress LessSpecificReturnStatement */
		return $response;
	}

	/**
	 * @param array<int|string, IdentifyMethod> $identifyMethods
	 */
	private function buildIdentifyKey(array $identifyMethods): string {
		if (empty($identifyMethods)) {
			return '';
		}
		$pairs = array_map(
			fn (IdentifyMethod $identifyMethod): string => $identifyMethod->getIdentifierKey() . ':' . $identifyMethod->getIdentifierValue(),
			array_values($identifyMethods),
		);
		sort($pairs);
		return implode('|', $pairs);
	}

	/**
	 * @param File[] $childFiles
	 * @return array{
	 *     signers: array<int, SignRequest>,
	 *     identifyMethods: array<int, array<string, IdentifyMethod>>,
	 *     visibleElements: array<int, FileElement[]>,
	 *     metadataByFileId: array<int, array<string, mixed>>
	 * }
	 */
	private function getEnvelopeChildContext(array $childFiles): array {
		$childFileIds = array_map(fn (File $file) => $file->getId(), $childFiles);
		$childSigners = $childFileIds ? $this->signRequestMapper->getByMultipleFileId($childFileIds) : [];
		$childIdentifyMethods = $this->signRequestMapper->getIdentifyMethodsFromSigners($childSigners);
		$childVisibleElements = [];
		$fileElements = $this->fileElementService->getByFileIds($childFileIds);
		foreach ($fileElements as $fileElement) {
			$signRequestId = $fileElement->getSignRequestId();
			if ($signRequestId === null) {
				continue;
			}
			$childVisibleElements[$signRequestId][] = $fileElement;
		}

		$metadataByFileId = [];
		foreach ($childFiles as $childFile) {
			$metadataByFileId[$childFile->getId()] = $childFile->getMetadata() ?? [];
		}

		return [
			'signers' => $childSigners,
			'identifyMethods' => $childIdentifyMethods,
			'visibleElements' => $childVisibleElements,
			'metadataByFileId' => $metadataByFileId,
		];
	}

	private function applyEnvelopeVisibleElementsByKey(
		array $signers,
		array $envelopeIdentifyMethods,
		array $childIdentifyMethods,
		array $childVisibleElements,
		array $metadataByFileId,
	): array {
		if (empty($childVisibleElements)) {
			return $signers;
		}

		$visibleElementsByKey = [];
		foreach ($childVisibleElements as $signRequestId => $elements) {
			if (empty($elements)) {
				continue;
			}
			$identifyMethodsOfSigner = $childIdentifyMethods[$signRequestId] ?? [];
			$signerKey = $this->buildIdentifyKey($identifyMethodsOfSigner);
			if ($signerKey === '') {
				continue;
			}

			$elementsByFileId = [];
			foreach ($elements as $element) {
				$elementsByFileId[$element->getFileId()][] = $element;
			}

			foreach ($elementsByFileId as $fileId => $fileElements) {
				$metadataForFile = $metadataByFileId[$fileId] ?? [];
				$formattedElements = $this->fileElementService->formatVisibleElements($fileElements, $metadataForFile);
				$visibleElementsByKey[$signerKey] = array_merge($visibleElementsByKey[$signerKey] ?? [], $formattedElements);
			}
		}

		foreach ($signers as $index => $signerData) {
			$signRequestId = $signerData['signRequestId'] ?? null;
			if ($signRequestId === null) {
				continue;
			}
			$identifyMethodsOfSigner = $envelopeIdentifyMethods[$signRequestId] ?? [];
			$signerKey = $this->buildIdentifyKey($identifyMethodsOfSigner);
			if ($signerKey === '') {
				continue;
			}
			$elements = $visibleElementsByKey[$signerKey] ?? [];
			if (empty($elements)) {
				continue;
			}
			$existingElements = $signerData['visibleElements'] ?? [];
			$mergedElements = array_merge($existingElements, $elements);
			$signers[$index]['visibleElements'] = $this->uniqueVisibleElements($mergedElements);
		}

		return $signers;
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @return array<int, array<string, mixed>>
	 */
	private function uniqueVisibleElements(array $elements): array {
		$unique = [];
		foreach ($elements as $element) {
			$elementId = $element['elementId'] ?? null;
			if ($elementId === null) {
				$unique[] = $element;
				continue;
			}
			$unique[$elementId] = $element;
		}
		return array_values($unique);
	}

	/**
	 * @param array<int, array<string, mixed>> $signers
	 * @return array<int, array<string, mixed>>
	 */
	private function collectVisibleElementsFromSigners(array $signers): array {
		$elements = [];
		foreach ($signers as $signer) {
			$signerElements = $signer['visibleElements'] ?? [];
			if (!empty($signerElements)) {
				$elements = array_merge($elements, $signerElements);
			}
		}
		return $this->uniqueVisibleElements($elements);
	}

	/**
	 * Format child files for response with signers
	 *
	 * @param File[] $files
	 * @return list<LibresignFileListItem>
	 * @psalm-suppress MoreSpecificReturnType
	 * @psalm-suppress LessSpecificReturnStatement
	 */
	private function formatChildFilesResponse(
		array $files,
		?array $allSignRequests = null,
		?array $identifyMethods = null,
	): array {
		$fileIds = array_map(fn (File $file) => $file->getId(), $files);
		$allSignRequests = $allSignRequests ?? ($fileIds ? $this->signRequestMapper->getByMultipleFileId($fileIds) : []);
		$identifyMethods = $identifyMethods ?? $this->signRequestMapper->getIdentifyMethodsFromSigners($allSignRequests);

		/**
		 * Index sign requests by file once to avoid repeatedly
		 * scanning the full collection while formatting child files.
		 */
		$signRequestsByFileId = [];

		foreach ($allSignRequests as $signRequest) {
			$signRequestsByFileId[$signRequest->getFileId()][] = $signRequest;
		}

		return array_values(array_map(function (File $file) use ($signRequestsByFileId, $identifyMethods) {

			$fileSignRequests = $signRequestsByFileId[$file->getId()] ?? [];

			$signers =
				$this->sponsorshipContextBuilderService
				->buildSignersWithSponsorshipContext(
					$file,
					$fileSignRequests,
				);
			$metadata = $file->getMetadata() ?? [];
			$size = $this->getFileSize($file);
			$signersFormatted = array_map(function (PersistedSignerSponsorshipDTO $persistedSigner) use ($identifyMethods) {
				$signer = $persistedSigner->getSignRequest();
				$identifyMethodsOfSigner = $identifyMethods[$signer->getId()] ?? [];
				$email = array_reduce($identifyMethodsOfSigner, function (string $carry, IdentifyMethod $identifyMethod): string {
					if ($identifyMethod->getIdentifierKey() === IdentifyMethodService::IDENTIFY_EMAIL) {
						return $identifyMethod->getIdentifierValue();
					}
					if (filter_var($identifyMethod->getIdentifierValue(), FILTER_VALIDATE_EMAIL)) {
						return $identifyMethod->getIdentifierValue();
					}
					return $carry;
				}, '');
				$displayName = array_reduce($identifyMethodsOfSigner, function (string $carry, IdentifyMethod $identifyMethod): string {
					if (!$carry && $identifyMethod->getMandatory()) {
						return $identifyMethod->getIdentifierValue();
					}
					return $carry;
				}, $signer->getDisplayName());

				/** @var LibresignSignerSummary */
				return [
					'signRequestId' => $signer->getId(),
					'displayName' => $displayName,
					'email' => $email,
					'identifyMethods' => array_map(fn (IdentifyMethod $identifyMethod): array => [
						'method' => $identifyMethod->getIdentifierKey(),
						'value' => $identifyMethod->getIdentifierValue(),
						'mandatory' => $identifyMethod->getMandatory(),
					], array_values($identifyMethodsOfSigner)),
					'signed' => $signer->getSigned()?->format(\DateTimeInterface::ATOM),
					'status' => $signer->getSigned() ? 1 : 0,
					'statusText' => $signer->getSigned() ? $this->l10n->t('Signed') : $this->l10n->t('Pending'),
					'sponsorship' => $persistedSigner->getSponsorship()->toArray(),
				];
			}, $signers);

			return [
				'fileId' => $file->getId(),
				'id' => $file->getId(),
				'nodeId' => $file->getNodeId(),
				'uuid' => $file->getUuid(),
				'name' => $file->getName(),
				'status' => $file->getStatus(),
				'statusText' => $this->fileMapper->getTextOfStatus($file->getStatus()),
				'docmdpLevel' => $file->getDocmdpLevel(),
				'signersCount' => count($signers),
				'file' => $this->urlGenerator->linkToRoute('libresign.page.getPdf', ['uuid' => $file->getUuid()]),
				'metadata' => $metadata,
				'size' => $size,
				'signers' => $signersFormatted,
			];
		}, $files));
	}

	private function getFileSize(File $file): int {
		$nodeId = $file->getSignedNodeId() ?: $file->getNodeId();
		if ($nodeId === null || $file->getUserId() === '') {
			return 0;
		}

		try {
			$fileNode = $this->root->getUserFolder($file->getUserId())->getFirstNodeById($nodeId);
			if ($fileNode instanceof NodeFile && method_exists($fileNode, 'getSize')) {
				return max(0, (int)$fileNode->getSize());
			}
		} catch (\Throwable) {
			return 0;
		}

		return 0;
	}
}
