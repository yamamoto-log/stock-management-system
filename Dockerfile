# PHP 8.3 と Apache が入った公式のイメージを土台にする
# （開発の PC の PHP 8.3 と同じ版にそろえる）
FROM php:8.3-apache

# MariaDB / MySQL に PDO で接続するための拡張を入れる
RUN docker-php-ext-install pdo_mysql

# 本番用の PHP の設定を使う（エラーを画面に出さない など）
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# 公開フォルダを public/ にする（config/ や includes/ を URL で開けないようにする）
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Render が指定するポート（環境変数 PORT）で待ち受ける。指定がなければ 10000
ENV PORT=10000
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/:80>/:${PORT}>/' /etc/apache2/sites-available/000-default.conf

# アプリのファイルを箱の中にコピーする
COPY . /var/www/html/