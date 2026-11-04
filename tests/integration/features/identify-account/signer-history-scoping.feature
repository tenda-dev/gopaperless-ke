# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
Feature: identify account search - historical signer scoping

  # Regression guard for the cross-user enumeration leak in
  # IdentifyMethodMapper::searchByIdentifierValue(): the requester filter was
  # enforced only inside an embedded sub-query that did not take effect at
  # runtime, so SignerPlugin returned other requesters' historical signers.
  #
  # These scenarios exercise the REAL mapper (a mocked unit test would not have
  # caught the leak), asserting that a requester only ever sees signers from
  # documents they own.

  Scenario: Historical signer search is scoped to the requesting owner (no cross-user leak)
    Given as user "admin"
    And user "req-no-assoc" exists
    And run the command "group:adduser admin req-no-assoc" with result code 0
    And run the command "config:app:set core shareapi_allow_share_dialog_user_enumeration --value=no" with result code 0
    And run the command "config:app:set core shareapi_restrict_user_enumeration_full_match --value=yes" with result code 0
    And run the command "config:app:set core shareapi_only_share_with_group_members --value=no" with result code 0
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"email","enabled":true},{"name":"account","enabled":true}] |
    And sending "post" to ocs "/apps/libresign/api/v1/admin/certificate/openssl"
      | rootCert | {"commonName":"test"} |
    # admin owns a document whose signer is scoped-signer@found.tld
    And sending "post" to ocs "/apps/libresign/api/v1/request-signature"
      | file | {"url":"<BASE_URL>/apps/libresign/develop/pdf"} |
      | signers | [{"identifyMethods":[{"method":"email","value":"scoped-signer@found.tld"}]}] |
      | name | document |
    And the response should have a status code 200
    # a different manager, owning no document with this signer, must see nothing
    When as user "req-no-assoc"
    And sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=scoped-signer"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                     | value |
      | (jq).ocs.data \| length | 0     |

  Scenario: Requester still finds their own historical signer on a partial search
    Given as user "admin"
    And run the command "config:app:set core shareapi_allow_share_dialog_user_enumeration --value=no" with result code 0
    And run the command "config:app:set core shareapi_restrict_user_enumeration_full_match --value=yes" with result code 0
    And run the command "config:app:set core shareapi_only_share_with_group_members --value=no" with result code 0
    And sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/libresign/identify_methods"
      | value | (string)[{"name":"email","enabled":true},{"name":"account","enabled":true}] |
    And sending "post" to ocs "/apps/libresign/api/v1/admin/certificate/openssl"
      | rootCert | {"commonName":"test"} |
    And sending "post" to ocs "/apps/libresign/api/v1/request-signature"
      | file | {"url":"<BASE_URL>/apps/libresign/develop/pdf"} |
      | signers | [{"identifyMethods":[{"method":"email","value":"my-own-signer@found.tld"}]}] |
      | name | document |
    And the response should have a status code 200
    When sending "get" to ocs "/apps/libresign/api/v1/identify-account/search?search=my-own-signer"
    Then the response should have a status code 200
    And the response should be a JSON array with the following mandatory values
      | key                                                               | value                   |
      | (jq).ocs.data \| map(select(.method == "email")) \| length        | 1                       |
      | (jq).ocs.data \| map(select(.method == "email")) \| .[0].identify  | my-own-signer@found.tld |
