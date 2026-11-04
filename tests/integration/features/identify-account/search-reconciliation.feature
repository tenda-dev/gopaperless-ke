# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
Feature: identify account search - Stage 2 identity reconciliation

  # Stage 2 reconciliation outcomes visible from the search endpoint:
  #  - Option A: an external suggestion is suppressed when the address resolves
  #    to an existing but non-discoverable internal account.
  #  - A genuine external address (no account) is still returned as an email
  #    signer (guards against over-suppression).
  #  - A discoverable account resolves to a single account row with no external
  #    duplicate.
  #
  # The historical-signer paths (preserve a requester's prior email signer for a
  # non-discoverable account; upgrade + dedupe a historical against a
  # discoverable account) require a pre-existing, requester-scoped sign-request
  # fixture that is impractical to provision inline; those are covered by
  # SignerIdentityReconcilerTest.

  Scenario: External suggestion is suppressed for a non-discoverable internal account (Option A)
    Given as user "admin"
    And run the command "group:add policy-isolated" with result code 0
    And user "policy-hidden" exists
    And set the email of user "policy-hidden" to "policy-hidden@found.tld"
    And run the command "group:adduser policy-isolated policy-hidden" with result code 0
    And run the command "config:app:set core shareapi_allow_share_dialog_user_enumeration --value=no" with result code 0
    And run the command "config:app:set core shareapi_restrict_user_enumeration_full_match --value=yes" with result code 0
    And run the command "config:app:set core shareapi_restrict_user_enumeration_full_match_email --value=yes" with result code 0
    And run the command "config:app:set core shareapi_only_share_with_group_members --value=yes" with result code 0
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"email","enabled":true},{"name":"account","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=policy-hidden@found.tld"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                                                          | value |
      | (jq).ocs.data \| map(select(.method == "account")) \| length | 0     |
      | (jq).ocs.data \| map(select(.method == "email")) \| length   | 0     |

  Scenario: Genuine external address with no account is kept as an email signer
    Given as user "admin"
    And run the command "config:app:set core shareapi_allow_share_dialog_user_enumeration --value=no" with result code 0
    And run the command "config:app:set core shareapi_restrict_user_enumeration_full_match --value=yes" with result code 0
    And run the command "config:app:set core shareapi_only_share_with_group_members --value=no" with result code 0
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"email","enabled":true},{"name":"account","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=nobody-here@found.tld"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                                                               | value                 |
      | (jq).ocs.data \| map(select(.method == "account")) \| length      | 0                     |
      | (jq).ocs.data \| map(select(.method == "email")) \| length        | 1                     |
      | (jq).ocs.data \| map(select(.method == "email")) \| .[0].identify  | nobody-here@found.tld |

  Scenario: Discoverable account resolves to a single account row with no external duplicate
    Given as user "admin"
    And user "reconcile-visible" exists
    And set the email of user "reconcile-visible" to "reconcile-visible@found.tld"
    And run the command "config:app:set core shareapi_allow_share_dialog_user_enumeration --value=no" with result code 0
    And run the command "config:app:set core shareapi_restrict_user_enumeration_full_match --value=yes" with result code 0
    And run the command "config:app:set core shareapi_restrict_user_enumeration_full_match_email --value=yes" with result code 0
    And run the command "config:app:set core shareapi_only_share_with_group_members --value=no" with result code 0
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"email","enabled":true},{"name":"account","enabled":true}] |
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=reconcile-visible@found.tld"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                                                          | value |
      | (jq).ocs.data \| map(select(.method == "account")) \| length | 1     |
      | (jq).ocs.data \| map(select(.method == "email")) \| length   | 0     |
