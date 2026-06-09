#!/bin/bash

set -e

echo "🚀 Bootstrapping GoPaperless staging..."

cd /var/www/html/apps-extra/libresign

echo "🔒 Configuring Git safe directories..."
git config --global --add safe.directory /var/www/html
git config --global --add safe.directory /var/www/html/apps-extra/libresign

echo "📦 Initialising submodules..."
git submodule update --init --recursive

echo "📦 Installing Composer dependencies..."
if [[ ! -f vendor/autoload.php ]]; then
	composer install --no-interaction
fi

echo "🔧 Enabling LibreSign..."
php occ app:enable libresign

echo "🔐 Installing LibreSign dependencies..."
php occ libresign:install --use-local-cert --java || true
php occ libresign:install --use-local-cert --pdftk || true
php occ libresign:install --use-local-cert --jsignpdf || true

echo "🔑 Configuring OpenSSL..."
php occ libresign:configure:openssl \
	--cn=CommonName \
	--c=BR \
	--ou=OrganizationUnit \
	--st=RioDeJaneiro \
	--o=LibreSign \
	--l=RioDeJaneiro || true

echo "⚙️ Applying GoPaperless defaults..."

php occ config:app:set libresign extra_settings --value=1

php occ config:system:set defaultapp \
	--value=libresign

echo "🎨 Updating theme..."
php occ maintenance:theme:update

echo ""
echo "✅ GoPaperless staging bootstrap completed!"
