<!--
  - SPDX-FileCopyrightText: 2026 Tenda World
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  The Terms of Use and the Data Protection Policy, transcribed word for word
  from the PDFs in img/legal, which stay the official copies. Edit the text
  here and replace the PDF together.
-->
<template>
	<main class="pu__legal">
		<article v-if="document === 'terms'" class="pu__doc">
			<h1>Terms of Use of GoPaperless.ke</h1>
			<p class="pu__doc-meta">
				Last Updated: 01 JANUARY 2025 · <a :href="termsPdf" download="Terms of Use of GoPaperless.pdf">Download the PDF</a>
			</p>

			<p>Welcome to GoPaperless.ke, a service provided by Tendaworld Ltd. ("we," "us," or "our"). These Terms of Use ("Terms") govern your access to and use of the GoPaperless.ke website, application, API, and related services (collectively, the "Services").</p>
			<p>By creating an account, accessing, or using the Services, you agree to be bound by these Terms. If you are using the Services on behalf of an organization, you represent that you have the authority to bind that organization to these Terms, and "you" and "your" will refer to that organization.</p>

			<h2>1. Definitions</h2>
			<ul>
				<li>"Account" means a unique account created for you to access our Services.</li>
				<li>"Customer Data" means any documents, templates, information, or data uploaded, stored, or processed through your Account.</li>
				<li>"Electronic Signature" means an electronic sound, symbol, or process attached to or logically associated with a record and executed or adopted by a person with the intent to sign the record.</li>
				<li>"Signer" means any individual or entity designated by a User to review, accept, or sign a document via the Services.</li>
				<li>"User" means any individual or entity utilizing the Services to send, manage, or sign documents.</li>
			</ul>

			<h2>2. Electronic Signatures and Legal Recognition</h2>
			<p><b>2.1 Consent to Electronic Records:</b> By using the Services, you consent to do business electronically and to sign documents using Electronic Signatures. You agree that Electronic Signatures executed through the Services are intended to be legally binding equivalents of traditional handwritten signatures.</p>
			<p><b>2.2 User Responsibility for Legal Validity:</b> You acknowledge that laws governing Electronic Signatures (such as the KENYA KICA (Cap. 411A), ECDNA regulation, CMCA (Cap. 79C), or US ESIGN Act, UETA, or CHINA ESL, or INDIA IT Act, or the EU eIDAS regulation) vary by jurisdiction. You are solely responsible for determining whether a particular document or contract is appropriate for execution via an Electronic Signature and whether it satisfies the legal requirements of your jurisdiction.</p>
			<p><b>2.3 Platform Status:</b> Tendaworld Ltd. is not a party to any agreement, contract, or document executed between Users and Signers through the Services. We make no representations or warranties regarding the legal validity, enforceability, or binding nature of any document signed using the Services.</p>

			<h2>3. Account Registration and Security</h2>
			<p><b>3.1 Account Creation:</b> To use certain features, you must register for an Account. You agree to provide accurate, current, and complete information during registration and to update such information as necessary.</p>
			<p><b>3.2 Security:</b> You are responsible for safeguarding your login credentials. You agree not to disclose your password to any third party and to notify us immediately of any unauthorized use of your Account. You are responsible for all activities that occur under your Account.</p>

			<h2>4. Acceptable Use and Restrictions</h2>
			<p>You agree to use the Services only for lawful purposes. You shall not:</p>
			<ul>
				<li>Use the Services to send, store, or sign any documents that are fraudulent, defamatory, obscene, or illegal.</li>
				<li>Upload viruses, malware, or any other malicious code.</li>
				<li>Attempt to gain unauthorized access to the Services, other User accounts, or our underlying infrastructure.</li>
				<li>Resell, sublicense, timeshare, or otherwise share the Services with any third party without our prior written consent.</li>
				<li>Reverse engineer, decompile, or disassemble any part of the Services.</li>
			</ul>

			<h2>5. Customer Data and Privacy</h2>
			<p><b>5.1 Ownership:</b> You retain all rights and ownership to your Customer Data. By uploading Customer Data, you grant us a limited, worldwide, non-exclusive license to host, process, and display the Customer Data strictly as necessary to provide the Services.</p>
			<p><b>5.2 Privacy Policy:</b> Our collection, use, and disclosure of personal information are governed by our <a :href="privacyPage">Privacy Policy</a>.</p>
			<p><b>5.3 Document Retention:</b> While we may provide storage for executed documents, you are solely responsible for retaining copies of your documents. We reserve the right to delete Customer Data from inactive accounts after 7 days of notice, in accordance with our data retention policies.</p>

			<h2>6. Subscription, Fees, and Payment</h2>
			<p><b>6.1 Billing:</b> Use of premium features requires payment of subscription fees. Fees are billed in advance on a recurring basis (e.g., monthly or annually) as selected during purchasing. All fees are non-refundable unless otherwise stated or required by law.</p>
			<p><b>6.2 Taxes:</b> You are responsible for all applicable taxes associated with your use of the Services, excluding taxes based on our net income.</p>
			<p><b>6.3 Downgrades and Cancellation:</b> You may cancel your subscription at any time. Cancellation will take effect at the end of your current billing cycle.</p>

			<h2>7. Intellectual Property</h2>
			<p>Tendaworld Ltd. and its licensors retain all rights, title, and interest in and to the Services, including all underlying software, technology, interfaces, logos, and trademarks. No rights are granted to you other than the limited right to use the Services in accordance with these Terms.</p>

			<h2>8. Warranties and Disclaimers</h2>
			<p><b>8.1 No Legal Advice:</b> The Services, templates, and any associated materials are provided for operational efficiency and do not constitute legal advice.</p>
			<p><b>8.2 "As-Is" Provision:</b> THE SERVICES ARE PROVIDED "AS IS" AND "AS AVAILABLE." TO THE MAXIMUM EXTENT PERMITTED BY LAW, Tendaworld Ltd. DISCLAIMS ALL WARRANTIES, EXPRESS OR IMPLIED, INCLUDING WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT. WE DO NOT WARRANT THAT THE SERVICES WILL BE UNINTERRUPTED, ERROR-FREE, OR COMPLETELY SECURE.</p>

			<h2>9. Limitation of Liability</h2>
			<p>TO THE FULLEST EXTENT PERMITTED BY LAW, IN NO EVENT SHALL Tendaworld Ltd., ITS DIRECTORS, EMPLOYEES, OR AFFILIATES BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING LOST PROFITS, LOST DATA, OR BUSINESS INTERRUPTION. OUR TOTAL CUMULATIVE LIABILITY ARISING OUT OF OR RELATING TO THESE TERMS SHALL NOT EXCEED THE AMOUNT PAID BY YOU TO US IN THE TWELVE (12) MONTHS PRECEDING THE EVENT GIVING RISE TO THE CLAIM.</p>

			<h2>10. Indemnification</h2>
			<p>You agree to indemnify, defend, and hold harmless Tendaworld Ltd. and its officers, directors, employees, and agents from any claims, damages, liabilities, and expenses (including attorneys' fees) arising from: (a) your use of the Services; (b) your violation of these Terms; or (c) any dispute between you and any Signer or third party regarding a document processed through the Services.</p>

			<h2>11. Termination</h2>
			<p><b>11.1 By You:</b> You may terminate these Terms at any time by closing your Account and ceasing all use of the Services.</p>
			<p><b>11.2 By Us:</b> We may suspend or terminate your Account if you violate these Terms, fail to pay applicable fees, or if required by law. We will provide reasonable notice before suspending your Account, except in cases of severe violations or security risks.</p>
			<p><b>11.3 Effect of Termination:</b> Upon termination, your right to use the Services will immediately cease. Sections governing intellectual property, disclaimers, liability limitations, and indemnification shall survive termination.</p>

			<h2>12. Governing Law and Dispute Resolution</h2>
			<p><b>12.1 Governing Law:</b> These Terms shall be governed by and construed in accordance with the laws of The Republic Of Kenya, without regard to its conflict of law principles.</p>
			<p><b>12.2 Arbitration:</b> Any dispute arising out of or in connection with these Terms shall be resolved by binding arbitration administered by the Nairobi Centre for International Arbitration in Nairobi, Kenya.</p>

			<h2>13. Modifications to Terms</h2>
			<p>We reserve the right to modify these Terms at any time. We will notify you of material changes by posting the updated Terms on our website and/or sending an email to the address associated with your Account. Your continued use of the Services after the effective date of the revised Terms constitutes your acceptance of the changes.</p>

			<h2>14. Contact Information</h2>
			<p>If you have any questions about these Terms, please contact us at: Tendaworld Ltd. | P.O. Box 386 – 00517, Nairobi, Kenya | <a href="mailto:mail@tenda.world">mail@tenda.world</a> | +254778146112</p>
		</article>

		<article v-else class="pu__doc">
			<h1>Data Protection Policy</h1>
			<p class="pu__doc-meta">
				<a :href="privacyPdf" download="Privacy policy.pdf">Download the PDF</a>
			</p>

			<h2>1 Introduction</h2>
			<p>JULICA is the data controller and processor of the information provided for registration, application, training, and certification purposes, as well as support services.</p>
			<p>As declared in our data protection policy, we are dedicated to and responsible for processing the information of our employees, customers, stakeholders, and other interested parties with full caution and confidentiality. This policy describes how we collect, store, handle and secure our data.</p>
			<p>The rules outlined in this document any form of data, be them stored electronically, on paper, or on any other storage device.</p>

			<h2>2 Privacy Elements</h2>
			<p>As part of our operations, we gather and process information or data that can make individuals identifiable, including, but not limited to, full name, phone number, account credentials, and home address.</p>
			<p>The use of personal data by JULICA is governed by the Data Protection Act of Kenya, European General Data Protection Regulation (EU-GDPR), and other national privacy legislation that offer the same level of data protection as the GDPR.</p>
			<p>We are committed to process all personal data under our control in accordance with data protection principles.</p>
			<p>The data we process data will be:</p>
			<ul>
				<li>Processed in a lawful, fair, and transparent manner</li>
				<li>Collected only for specific, explicit, and limited purposes (purpose limitation)</li>
				<li>Adequate, relevant, and not excessive (data minimization)</li>
				<li>Accurate and kept up-to-date, where necessary</li>
				<li>Kept for no longer than necessary (retention)</li>
				<li>Handled with appropriate security and confidentiality</li>
			</ul>

			<h2>3 Roles and Responsibilities</h2>
			<p>All JULICA employees and collaborators are responsible for ensuring that the collection, storage, handling, and protection of data is done appropriately. The contact details of our Data Protection Officer are:</p>
			<p>
				Person: Data Protection Officer<br>
				Email: privacy@tendaworld.com
			</p>
			<p>The following are the responsibilities of specific people or departments:</p>

			<h3>3.1 Data Protection Officer</h3>
			<ul>
				<li>Inform and advise the controller or processor and their employees of their obligations under data protection laws</li>
				<li>Act as a contact point for requests from individuals regarding the processing of their personal data and the exercise of their rights</li>
				<li>Oversee and implement data protection strategies</li>
				<li>Conduct regular assessments and audits to ensure GDPR compliance</li>
			</ul>

			<h3>3.2. Information Security Manager</h3>
			<ul>
				<li>Oversee and improve cybersecurity awareness programs and risk management regularly</li>
				<li>Collaborate with the Security Committee in leading the design, implementation, operation, and maintenance of the information security management system.</li>
				<li>Ensure periodic testing are conducted to evaluate the security posture of information security</li>
				<li>Lead the design and operation of related compliance monitoring and improvement activities to ensure compliance both with internal security policies and applicable laws and regulations</li>
				<li>Develop and manage controls to ensure compliance with the requirements of various security laws, standards, and regulations</li>
			</ul>

			<h3>3.3. IT Systems Manager</h3>
			<ul>
				<li>Strictly comply with all JULICA CA policies related to non-disclosure, non-competition, and the confidentiality of information</li>
				<li>Constantly stay up to date on various web technologies and tools</li>
				<li>Perform networking systems hardware and software upgrades and install security patches, as needed</li>
				<li>Check and monitor the general health of networks and networking devices</li>
				<li>Perform daily system monitoring, verify the integrity and availability of all hardware, server resources, systems, and IT processes, review system and application logs, and verify completion of scheduled jobs such as backups</li>
				<li>Implement, configure, and maintain computer networks, software, and digital security</li>
			</ul>

			<h3>3.4. Compliance Department</h3>
			<ul>
				<li>Ensure that access to the personal data of Certification holders, Trainers, Examiners, Examinees, and Invigilators will not be shared with or provided to unauthorized parties</li>
				<li>Additional documents and data provided by applicants are being stored appropriately and centralized to ensure the confidentiality, integrity, availability of the data</li>
			</ul>

			<h3>3.5. Business Development Department</h3>
			<p>Ensure that access to JULICA Authorized users and Agents’ personal data:</p>
			<ul>
				<li>Is restricted to authorized personnel only</li>
				<li>Will not be shared with or provided to unauthorized parties</li>
			</ul>

			<h3>3.6. System Administrator</h3>
			<p>Ensure that access to the personal data of members registered on the JULICA Website:</p>
			<ul>
				<li>Is restricted to authorized personnel only</li>
				<li>Will not be shared with or provided to unauthorized parties</li>
			</ul>

			<h2>4 General Guidelines</h2>
			<ul>
				<li>Personal data of stakeholders shall be restricted only to employees who need it to complete their job in line with their job responsibilities.</li>
				<li>Informally sharing data is prohibited. When access to confidential information is needed, employees shall request it from their immediate superior.</li>
				<li>All JULICA CA employees shall undergo comprehensive training to help them understand their responsibilities when handling personal data.</li>
				<li>Data in the process by employees shall be kept secure and stored following the data storage guidelines presented in the chapter below.</li>
				<li>In particular, account credentials and passwords shall be kept in encrypted storage with restricted access.</li>
				<li>Personal data shall not be disclosed or communicated to unauthorized people, either within the company or externally.</li>
				<li>When unsure about any aspect of data protection, employees shall request assistance from their immediate superior or the Data Protection Officer.</li>
			</ul>

			<h2>5 Data Storage</h2>
			<p>These rules describe the storage and the process of safely storing data. Data is stored electronically shall be limited to authorized personnel only. The guideline also applies to electronically stored data printed out for specific reasons.</p>
			<ul>
				<li>Employees with access to electronic files shall ensure confidentiality.</li>
			</ul>
			<p>Data shall be protected from unauthorized access, accidental deletion, and malicious hacking attempts.</p>
			<ul>
				<li>Data shall be protected with strong passwords that are changed regularly and never shared between employees.</li>
				<li>Data shall not be stored on removable media (like a CD or DVD). If necessary for job purposes, removable media shall be kept locked and secure.</li>
				<li>Data shall only be stored on designated servers at JULICA premises and shall only be uploaded onto approved cloud computing services.</li>
				<li>Servers containing personal data shall be sited in a secure location where access is restricted to authorized personnel only. The site must be monitored and access-controlled.</li>
				<li>Data shall be backed up daily. Backups shall be tested regularly, in line with the company’s standard backup procedures.</li>
				<li>Data shall never be saved directly to laptops or other mobile devices (e.g., tablets or smartphones).</li>
				<li>All servers and computers containing data shall be protected by approved security software and a firewall.</li>
				<li>All data entering JULICA systems and website are stored as unique and measures to prevent privilege escalation are taken.</li>
				<li>All data entering into the database of the JULICA website are protected with certificates that ensure encrypted communication when receiving and sending information.</li>
			</ul>

			<h2>6 Data Usage</h2>
			<ul>
				<li>All data collected by JULICA CA are strictly for JULICA-related services. They are used to provide complete responses or services. No other non-JULICA related service will be offered from the data collected.</li>
				<li>When working with personal data, employees shall ensure their computer screens are always locked when left unattended.</li>
				<li>Data shall be encrypted before being transferred electronically.</li>
				<li>Employees shall not save copies of personal data to their computers. Always access and update the central copy of any data.</li>
			</ul>

			<h2>7 Data Accuracy and Action</h2>
			<p>To exercise data protection, JULICA CA takes reasonable steps and is committed to:</p>
			<ul>
				<li>Restrict and monitor access to sensitive data</li>
				<li>Establish effective data collection procedures</li>
				<li>Provide employees with online privacy and security training</li>
				<li>Build secure networks to protect online data from cyberattacks</li>
				<li>Establish clear procedures for reporting privacy breaches or data misuse</li>
				<li>Include contract clauses or communicate statements on how we handle data</li>
				<li>Update the data continuously</li>
				<li>Ensure that marketing databases are checked against industry suppression files</li>
				<li>Install tracking logs to monitor employees’ activities ensuring data is not being misused</li>
				<li>Install firewall and protection software that prevents employees from sharing and distributing data from JULICA CA devices externally by means of detecting large amounts of data being transferred via email or external drives</li>
				<li>Establish data protection practices (document shredding, secure locks, data encryption, frequent backups, access authorization, etc.)</li>
			</ul>

			<h2>8 Subject Access Requests</h2>
			<p>All individuals and organizations who are subject of personal and other data held by JULICA are entitled to:</p>
			<ul>
				<li>Ask what information JULICA holds about them and why</li>
				<li>Ask how to gain access to it</li>
				<li>Be informed on how to keep it up to date</li>
				<li>Be informed on how the company meets its data protection obligations</li>
			</ul>
			<p>Our clients can request such information directly through a subject access request made via email. We will always verify the identity of anyone making a subject access request before handing over any information. Confirmation will be asked from the data subject using the email data subject used to register an account at JULICA. We aim to respond to the request within 14 days.</p>

			<h3>8.1 Data Modification</h3>
			<p>Our clients can request data modification or correction via email or through the digital form available. JULICA will verify the identity of anyone making a request before modifying or correcting any information.</p>

			<h3>8.2 Data Erasure</h3>
			<p>The data subject will be provided with all necessary information before erasure. Before proceeding with the erasure, the data subject will receive a statement from our Data Protection Officer explaining the outcome of the data being deleted. Erasure of data can be requested at any time.</p>

			<h2>9 Children</h2>
			<p>Our website is not intended for children or persons younger than 18. JULICA does not knowingly collect personally identifiable information (PII) of persons under the age of 18. We strive to comply with the provisions of The Data protection Act of Kenya and the European Union General Data Protection Regulation (EU GDPR). If you are a parent or custodian of a child or person under 18 years old and you believe that they have provided us with information about themselves, please contact us</p>

			<h2>10 Disclosing data</h2>
			<p>In certain circumstances, when required, JULICA can disclose data to law enforcement agencies without the consent of the data subject. However, the data controller will ensure the request is lawful, seeking assistance from the board and from the company’s legal advisors, where necessary.</p>

			<h2>11 Privacy Statement.</h2>
			<p>We have a privacy statement available on our website. It presents the type information we collect, the purpose of collection and use, third-party processors involved, and how we protect customers' data. The privacy statement is available at <a href="https://www.julica.com/policies/privacy/" target="_blank" rel="noopener">https://www.julica.com/policies/privacy/</a></p>
		</article>
	</main>
</template>

<script setup lang="ts">
import { generateUrl } from '@nextcloud/router'
import { watchEffect } from 'vue'

import privacyPdf from '../../img/legal/data-protection-policy.pdf'
import termsPdf from '../../img/legal/terms-of-use.pdf'

defineOptions({ name: 'PublicLegal' })

const props = defineProps<{
	document: 'terms' | 'privacy'
}>()

const privacyPage = generateUrl('/apps/libresign/p/privacy')

watchEffect(() => {
	window.document.title = `${props.document === 'terms' ? 'Terms of Use' : 'Data Protection Policy'} - GoPaperless`
})
</script>

<style scoped lang="scss">
.pu__legal {
	padding-block: clamp(32px, 6vw, 72px);
	padding-inline: var(--pu-pad);
}

// One readable column, in the landing's type.
.pu__doc {
	max-width: 72ch;
	font-size: 16px;
	line-height: 1.65;
	color: var(--ink);

	h1 {
		margin: 0 0 8px;
		font-size: clamp(30px, 4vw, 44px);
		font-weight: 700;
		line-height: 1.1;
		letter-spacing: -.02em;
		text-wrap: balance;
	}

	h2 {
		margin: 40px 0 12px;
		font-size: 21px;
		line-height: 1.3;
	}

	h3 {
		margin: 28px 0 8px;
		font-size: 17px;
		line-height: 1.35;
	}

	p,
	ul {
		margin: 0 0 14px;
	}

	// Nextcloud's base styles drop list bullets.
	ul {
		padding-inline-start: 22px;
		list-style: disc;
	}

	li + li {
		margin-top: 6px;
	}

	a {
		color: var(--brand-strong);
		text-decoration: underline;
		text-underline-offset: 2px;
	}
}

.pu__doc-meta {
	padding-bottom: 20px;
	border-bottom: 1px solid var(--line);
	font-size: 14px;
	color: var(--slate);
}
</style>
