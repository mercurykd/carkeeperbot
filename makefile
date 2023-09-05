b:
	docker compose build
e:
	docker compose run --rm poll sh
c:
	docker compose run --rm cron sh
u:
	docker compose up --build -d
d:
	docker compose down
r: d u
cron:
	docker compose exec cron sh
poll:
	docker compose exec poll sh
ps:
	docker compose ps
