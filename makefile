b:
	docker compose build
e:
	docker compose run --rm poll sh
c:
	docker compose run --rm cron sh
u:
	docker compose up --build -d
a:
	docker compose up --build
d:
	docker compose down
r: d u
ra: d a
cron:
	docker compose exec cron sh
poll:
	docker compose exec poll sh
ps:
	docker compose ps
l:
	docker compose logs
