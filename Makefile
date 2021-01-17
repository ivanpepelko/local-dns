build:
	docker-compose build

push:
	docker push -a jarvis:5000/aperture/local-dns

upgrade: build push
	curl -X POST http://192.168.1.200:9000/api/webhooks/26ea8af0-8880-4b21-b1af-fb11e3344e1a