<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\AutoChargeDTO;
use OCA\Libresign\Service\Payment\DTO\MnoRoutingResultDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentRoutingResultDTO;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PaymentRoutingService {
    public function __construct(
        private PhoneMnoResolver $phoneMnoResolver,
        private MnoRoutingRegistry $mnoRoutingRegistry,
        private PaymentCountryResolver $countryResolver,
		private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve a mobile-money phone number into its payment route.
     *
     * This is the single source of truth for phone → MNO → provider routing.
     */
    public function resolveMobileMoney(
        string $phoneNumber,
        bool $forceRefresh = false,
        ?PaymentProvider $providerHint = null,
    ): PaymentRoutingResultDTO {
        // Phone resolution precedence: override > cache > libphonenumber + MNO detection > fallback.
        // The resolver owns validity so an active override can rescue a
        // number libphonenumber would reject; the rail stays with MnoRoutingRegistry.
        $resolution = $this->phoneMnoResolver->resolve($phoneNumber, $forceRefresh);

        $identity = $resolution->identity;
        $region = $identity->region;
        $providerOverride = $resolution->providerOverride;

        if (!$identity->valid || !$region) {
            throw new RuntimeException('Unable to resolve phone number');
        }

        if (!$this->mnoRoutingRegistry->supportsRegion($region)) {
            throw new RuntimeException(sprintf(
                'Unsupported region: %s. Supported regions: %s',
                $region,
                implode(', ', $this->mnoRoutingRegistry->supportedRegions())
            ));
        }

        $countryCtx = $this->countryResolver->resolve($region);

        if (!$countryCtx) {
            throw new RuntimeException('Unsupported country');
        }

        $carrier = $identity->carrierHint;
        $confidence = $identity->confidence;

        $route = $providerOverride !== null
            ? $this->resolveOverrideRoute(
                $identity->mno,
                $providerOverride,
                $countryCtx->country,
                $region,
                $confidence,
            )
            : $this->mnoRoutingRegistry->route(
                PaymentCapability::MOBILE_MONEY,
                $countryCtx->country,
                $region,
                $carrier,
                $confidence,
            );

        if (!$route->capability) {
            throw new RuntimeException('Unable to determine payment route');
        }

        // Only use the caller's provider hint when there is no authoritative
        // override and the routing registry requires provider selection.
        if (
            $providerOverride === null
            && $providerHint !== null
            && $route->requiresUserSelection()
        ) {
            $route = $route->withPreferredProvider($providerHint);

			$this->logger->info('Using frontend provider hint for uncertain route', [
				'hint' => $providerHint,
				'route_confidence' => $route->confidence->value,
			]);
        } elseif (
			$providerHint !== null
			&& $route->capability === PaymentCapability::MOBILE_MONEY
		) {
			$this->logger->info('Ignoring frontend provider hint; backend route is authoritative', [
				'hint' => $providerHint,
				'route_provider' => $route->preferredProvider->value,
				'route_confidence' => $route->confidence->value,
			]);
		}

        $autoCharge = new AutoChargeDTO(
            enabled: $identity->verified
                && $providerOverride === PaymentProvider::DPO
                && $resolution->providerMnoKey !== null,
            provider: $providerOverride?->value,
            mno: $identity->mno,
            country: $identity->country,
            providerMnoKey: $resolution->providerMnoKey,
        );

        return new PaymentRoutingResultDTO(
            identity: $identity,
            route: $route,
            countryContext: $countryCtx,
            providerOverride: $providerOverride,
            providerMnoKey: $resolution->providerMnoKey,
            autoCharge: $autoCharge,
        );
    }

    private function resolveOverrideRoute(
        ?string $mno,
        PaymentProvider $provider,
        string $country,
        string $region,
        ResolutionConfidence $confidence,
    ): MnoRoutingResultDTO {
        if ($mno === null || $mno === '') {
            throw new RuntimeException(
                'Unable to determine canonical MNO for provider override'
            );
        }

        return $this->mnoRoutingRegistry->routeForMno(
            PaymentCapability::MOBILE_MONEY,
            $country,
            $region,
            $mno,
            $provider,
            $confidence,
        );
    }

	public function resolveCard(): MnoRoutingResultDTO {
		return $this->mnoRoutingRegistry->route(
			PaymentCapability::CARD,
			null,
			null,
			null,
			ResolutionConfidence::HIGH,
		);
	}
}
