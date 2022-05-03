@:
	@ echo "No default task"

## Tests
test:
	make test-wpunit

test-wpunit:
	cd ./custom/dev-env && \
	docker-compose exec -w "/project" test_php vendor/bin/phpunit

dev-env--shell-test:
	cd ./custom/dev-env && docker-compose exec test_php bash

## Development environment

### Setup
dev-env--up:
#	make wp-core-download
#	make wp-tests-lib-download
	make dev-env--download
	composer install
	cd ./custom/dev-env && make up
	@ echo "\nWaiting for mysql..."
	sleep 5
	make dev-env--install

wp-core-download:
	rm -rf ./custom/wp-core
	git clone --depth=1 --branch=5.9.3 git@github.com:WordPress/WordPress.git ./custom/wp-core
	rm -rf ./custom/wp-core/.git

wp-tests-lib-download:
	mkdir -p ./custom
	rm -rf ./custom/wp-tests-lib
	svn co https://develop.svn.wordpress.org/tags/5.9.3/tests/phpunit/includes ./custom/wp-tests-lib/includes
	svn co https://develop.svn.wordpress.org/tags/5.9.3/tests/phpunit/data     ./custom/wp-tests-lib/data
	svn co https://develop.svn.wordpress.org/tags/5.9.3/tests/phpunit/tests    ./custom/wp-tests-lib/tests

dev-env--download:
	rm -fr ./custom/dev-env && \
	mkdir -p ./custom/dev-env && \
	cd ./custom/dev-env && \
	git clone -b 5.4.42 --depth=1 -- git@github.com:wodby/docker4wordpress.git . && \
	rm ./docker-compose.override.yml && \
	cp ../../tools/dev-env/docker-compose.yml . && \
	cp ../../tools/dev-env/.env . && \
	cp ../../tools/dev-env/wp-config.php ../wp-core/

dev-env--install:
	cd ./custom/dev-env && \
	docker-compose exec mariadb mysql -uroot -ppassword -e "GRANT FILE on *.* to 'wordpress'@'%';" && \
	\
	make wp 'core install --url="http://pomodoro.docker.local:8000/" --title="Dev site" --admin_user="admin" --admin_password="admin" --admin_email="admin@docker.local" --skip-email' && \
	make wp 'language core install ru_RU --activate' && \
	\
	docker-compose exec mariadb mysql -uroot -ppassword -e "create database wordpress_test;" && \
	docker-compose exec mariadb mysql -uroot -ppassword -e "GRANT ALL PRIVILEGES ON wordpress_test.* TO 'wordpress'@'%';" && \
	docker-compose exec test_php wp core install --url="http://pomodoro.docker.local:8000/" --title="Testing site" --admin_user="admin" --admin_password="admin" --admin_email="admin@docker.local" --skip-email && \
	docker-compose exec test_php wp language core install ru_RU --activate

### Regular commands
dev-env--start:
	cd ./custom/dev-env && make start

dev-env--stop:
	cd ./custom/dev-env && make stop

dev-env--prune:
	cd ./custom/dev-env && make prune

dev-env--restart:
	cd ./custom/dev-env && make stop
	cd ./custom/dev-env && make start

dev-env--recreate:
	make dev-env--prune && make dev-env--up

dev-env--shell:
	cd ./custom/dev-env && make shell
