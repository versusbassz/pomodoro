@:
	@ echo "No default task"

wp-core-download:
	rm -rf ./custom/wp-core
	git clone --depth=1 --branch=5.9.3 git@github.com:WordPress/WordPress.git ./custom/wp-core
	rm -rf ./custom/wp-core/.git
