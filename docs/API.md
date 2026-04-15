# Документация по API

Базовая точка: `api/index.php?action=...`

## Авторизация

- Заголовок: `Authorization: Bearer <token>`
- Проверяются токены:
  - `api_token` (current),
  - `next_api_token` (до switch),
  - `prev_api_token` (до `prev_api_token_valid_until`).

Ошибки:
- `401` — нет заголовка;
- `403` — токен невалиден.

## Эндпоинты

### `GET ?action=get_hash`

Ответ:
- `hash` — текущий хеш активных номеров;
- `api_token`, `next_api_token`, `token_switch_at`;
- `server_time` (UNIX, время MySQL);
- `serial_debug`, `gateway_log_level`;
- `relay_delay_group1_ms`, `relay_pulse_ms`.

Назначение:
- проверка актуальности данных на шлюзе;
- доставка runtime-настроек шлюзу.

### `GET ?action=get_data`

Параметры:
- `hash` — локальный hash шлюза;
- `page`, `limit`;
- опционально:
  - `delta=1`
  - `since_ts=<unix>`
  - `batch=removed|upserts`

Режимы:
- Если `hash` совпал: `{"changed":false}`.
- Full list:
  - `changed=true`, `hash`, `total_pages`, `data:[{phone,group}]`.
- Delta:
  - `batch=removed` → `removed:[phone...]`;
  - `batch=upserts` → `data:[{phone,group}]`;
  - может вернуть `full_sync_required=true`.

### `POST ?action=log`

Тело JSON: массив событий.

Поддерживаются 2 формата ключей:
- полный: `event_type, phone_number, group_id, status, details`;
- компактный: `t, p, g, s, d`.

Ответ: `{"status":"ok"}`.

### `GET ?action=commands`

Возвращает pending-команды:
- SMS: `{"id","type":"send_sms","phone","message"}`
- USSD: `{"id","type":"send_ussd","code"}`

### `POST ?action=command_ack`

Тело:
- SMS: `{"id","status","kind":"sms"}`
- USSD: `{"id","status","kind":"ussd","result":"..."}`

Обновляет `status`, `sent_at`, `attempts` (и `result` для USSD).

### `GET ?action=firmware_meta`
### `GET ?action=firmware_chunk`

Служебные endpoints OTA; могут быть отключены флагом в API.

## Пример запроса (get_hash)

```http
GET /fgate/api/?action=get_hash HTTP/1.1
Host: example.com
Authorization: Bearer 0123456789abcdef0123456789abcdef
```

## Пример ответа (delta upserts)

```json
{
  "changed": true,
  "delta": true,
  "batch": "upserts",
  "hash": "77cf30a608c39c121d284a5686b7d559",
  "total_pages": 1,
  "page": 1,
  "data": [
    { "phone": "+796114014796", "group": 0 },
    { "phone": "+796114226643", "group": 1 }
  ]
}
```

