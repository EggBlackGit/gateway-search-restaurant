# setup back end
    composer install
    copy .env.example .env
    php artisan key:generate
    php artisan storage:link
    php artisan serve
    **** set key GOOGLE_MAPS_API_KEY