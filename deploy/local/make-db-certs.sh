#!/bin/sh
# Creates the TLS material for the database, once.
#
#   /certs  server key + certificate   (mounted only into the database container)
#   /ca     the CA certificate         (mounted read-only into the application containers)
#
# The CA private key is discarded after signing, so nothing can mint further
# certificates for this database later. Delete the db-certs and db-ca volumes
# (docker compose down --volumes) to issue new ones.
set -eu

if [ -s /certs/server.key ] && [ -s /certs/server.crt ] && [ -s /ca/ca.crt ]; then
  echo "Database TLS material already present."
  exit 0
fi

cd /tmp
openssl ecparam -name prime256v1 -genkey -noout -out ca.key
openssl req -x509 -new -key ca.key -sha256 -days 3650 \
  -subj "/CN=Margin LMS local database CA" -out ca.crt

openssl ecparam -name prime256v1 -genkey -noout -out server.key
openssl req -new -key server.key -subj "/CN=db" -out server.csr
printf "subjectAltName=DNS:db,DNS:localhost\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n" > ext.cnf
openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial \
  -out server.crt -days 825 -sha256 -extfile ext.cnf

# 70 is the postgres user in the postgres:alpine image. The key must be 0600.
install -m 600 -o 70 -g 70 server.key /certs/server.key
install -m 644 -o 70 -g 70 server.crt /certs/server.crt
install -m 644 ca.crt /ca/ca.crt
rm -f ca.key
echo "Database TLS material created."
