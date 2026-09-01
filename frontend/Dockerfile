# Frontend CASA — build React (Vite) servi en statique par un nginx interne
# au conteneur. Le service "nginx" du docker-compose (reverse proxy externe)
# route les requêtes non-API vers ce conteneur.
FROM node:20-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:1.27-alpine
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
