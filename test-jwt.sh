#!/bin/bash
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0OjgwMDEvYXBpL2xvZ2luIiwiaWF0IjoxNzYzNDA0ODIwLCJleHAiOjE3NjM0MDg0MjAsIm5iZiI6MTc2MzQwNDgyMCwianRpIjoiWVgwR1BkNDJWcWhTRjRNbiIsInN1YiI6IjE2IiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyIsImVtYWlsIjoiand0dGVzdEBleGFtcGxlLmNvbSIsIm5hbWUiOiJKV1QgVGVzdCIsInN0YXR1c19pZCI6IjAxSzdIUktUSkE1WDFSQzBOREFQREQzTTYyIiwidHlwZSI6ItCQ0LTQvNC40L0iLCJlbWFpbF92ZXJpZmllZCI6ZmFsc2V9.39mGJ_xMGH_26_2SUWrS50aFDYlLylMYL5r3C-yPJaU"

curl -s -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d '{"query":"{ users { id name email } }"}'
