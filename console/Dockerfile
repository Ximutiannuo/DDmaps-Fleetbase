# ---- Build Stage ----
FROM node:22.22.0-alpine3.22 AS builder

WORKDIR /console

RUN mkdir -p ~/.pnpm
ENV PNPM_HOME=/root/.pnpm

ARG ENVIRONMENT=production
ARG DISABLE_FLEETBASE_ATTRIBUTION=false
ARG DISABLE_RUNTIME_CONFIG=false
ENV DISABLE_FLEETBASE_ATTRIBUTION=$DISABLE_FLEETBASE_ATTRIBUTION
ENV DISABLE_RUNTIME_CONFIG=$DISABLE_RUNTIME_CONFIG
# Bake config into the build: empty host = same-origin (works for both localhost and LAN IP)
ENV API_HOST=
ENV SOCKETCLUSTER_HOST=
ENV SOCKETCLUSTER_PORT=4200
ENV SOCKETCLUSTER_SECURE=
ENV PATH=/root/.pnpm/bin:$PATH
ARG HTTP_PROXY
ARG HTTPS_PROXY
ENV HTTP_PROXY=$HTTP_PROXY
ENV HTTPS_PROXY=$HTTPS_PROXY
ENV NO_PROXY=localhost,127.0.0.1,database,cache,application,socket

# Install global dependencies
RUN npm install -g ember-cli@5.4.2 pnpm@11.0.9
ARG ALPINE_MIRROR=https://dl-cdn.alpinelinux.org/alpine
RUN sed -i "s|https://dl-cdn.alpinelinux.org/alpine|$ALPINE_MIRROR|g" /etc/apk/repositories \
    && apk add --no-cache git openssh-client
RUN mkdir -p -m 0600 ~/.ssh && (ssh-keyscan github.com >> ~/.ssh/known_hosts 2>/dev/null || true)

# Copy package metadata first for dependency caching
COPY console/package.json console/pnpm-lock.yaml console/pnpm-workspace.yaml ./
COPY console/.npmr[c] ./

# Install app dependencies (lockfile may be updated for new deps)
RUN pnpm install --frozen-lockfile

# Copy the construction-site extension into the console project tree and link
# it into node_modules. Registering it as an in-repo addon via
# console/package.json (ember-addon.paths = ["packages/construction-site"])
# requires the package to live INSIDE the project tree, so its own requires
# (e.g. ember-engines) resolve by walking up to /console/node_modules.
# (Do NOT symlink a node_modules dir inside the package back to
# /console/node_modules — that creates a symlink cycle and the ember build
# fails with ELOOP.)
COPY packages/construction-site /console/packages/construction-site
RUN mkdir -p node_modules/@fleetbase && ln -sf ../../packages/construction-site node_modules/@fleetbase/construction-site-engine; rm -rf /console/packages/construction-site/node_modules

# Use authenticated CARTO tiles or fall back to OpenStreetMap with attribution.
COPY scripts/patch-carto.cjs /tmp/patch-carto.cjs
RUN --mount=type=secret,id=carto_api_key node /tmp/patch-carto.cjs

# Patch fleetops visibility manager: add leaflet-zoom-animated to custom panes for zoom sync
COPY packages/fleetops/addon/services/leaflet-layer-visibility-manager.js /tmp/fleetops-patches/leaflet-layer-visibility-manager.js
RUN cp /tmp/fleetops-patches/leaflet-layer-visibility-manager.js node_modules/@fleetbase/fleetops-engine/addon/services/leaflet-layer-visibility-manager.js

# Patch fleetops tracking marker: guard _applyRotation against undefined _icon during zoom animation
COPY packages/fleetops/addon/components/leaflet-tracking-marker.js /tmp/fleetops-patches/leaflet-tracking-marker.js
RUN cp /tmp/fleetops-patches/leaflet-tracking-marker.js node_modules/@fleetbase/fleetops-engine/addon/components/leaflet-tracking-marker.js

# The routes page uses the current FleetOps map component.
COPY packages/fleetops/addon/templates/operations/routes/index.hbs /tmp/fleetops-patches/operations-routes-index.hbs
RUN cp /tmp/fleetops-patches/operations-routes-index.hbs node_modules/@fleetbase/fleetops-engine/addon/templates/operations/routes/index.hbs

# Copy Chinese translations into installed engine packages (npm-published versions lack zh-cn)
COPY packages/fleetops/translations/zh-cn.yaml /tmp/zh-cn/fleetops.yaml
COPY packages/ledger/translations/zh-cn.yaml /tmp/zh-cn/ledger.yaml
COPY packages/pallet/translations/zh-cn.yaml /tmp/zh-cn/pallet.yaml
COPY packages/registry-bridge/translations/zh-cn.yaml /tmp/zh-cn/registry-bridge.yaml
COPY packages/customer-portal/translations/zh-cn.yaml /tmp/zh-cn/customer-portal.yaml
COPY packages/ai/translations/zh-cn.yaml /tmp/zh-cn/ai.yaml
RUN for dir in node_modules/@fleetbase/*-engine node_modules/@fleetbase/pallet node_modules/@fleetbase/registry-bridge-engine; do \
      if [ -d "$dir/translations" ]; then \
        base=$(basename "$dir"); \
        case "$base" in \
          fleetops-engine) cp /tmp/zh-cn/fleetops.yaml "$dir/translations/zh-cn.yaml" ;; \
          ledger-engine) cp /tmp/zh-cn/ledger.yaml "$dir/translations/zh-cn.yaml" ;; \
          pallet|pallet-engine) cp /tmp/zh-cn/pallet.yaml "$dir/translations/zh-cn.yaml" ;; \
          registry-bridge-engine) cp /tmp/zh-cn/registry-bridge.yaml "$dir/translations/zh-cn.yaml" ;; \
          customer-portal-engine) cp /tmp/zh-cn/customer-portal.yaml "$dir/translations/zh-cn.yaml" ;; \
          ai-engine) cp /tmp/zh-cn/ai.yaml "$dir/translations/zh-cn.yaml" ;; \
        esac; \
      fi; \
    done

# Patch ember-core services: fetch uses window.location.host (includes port), socket auto-detects secure
COPY packages/ember-core/addon/services/fetch.js /tmp/ember-core-fetch.js
COPY packages/ember-core/addon/services/socket.js /tmp/ember-core-socket.js
COPY packages/ember-core/addon/utils/load-installed-extensions.js /tmp/ember-core-load-installed-extensions.js
COPY packages/ember-core/addon/utils/gps-uploader.js /tmp/ember-core-gps-uploader.js
COPY packages/ember-core/addon/services/current-user.js /tmp/ember-core-current-user.js
RUN cp /tmp/ember-core-current-user.js node_modules/@fleetbase/ember-core/addon/services/current-user.js
RUN cp /tmp/ember-core-gps-uploader.js node_modules/@fleetbase/ember-core/addon/utils/gps-uploader.js
RUN cp /tmp/ember-core-fetch.js node_modules/@fleetbase/ember-core/addon/services/fetch.js && \
    cp /tmp/ember-core-socket.js node_modules/@fleetbase/ember-core/addon/services/socket.js && \
    cp /tmp/ember-core-load-installed-extensions.js node_modules/@fleetbase/ember-core/addon/utils/load-installed-extensions.js

# Patch ember-ui set-container-dimensions: use CSS 100% instead of fixed pixels to prevent stale dimensions
COPY packages/ember-ui/addon/modifiers/set-container-dimensions.js /tmp/ember-ui-set-container-dimensions.js
RUN cp /tmp/ember-ui-set-container-dimensions.js node_modules/@fleetbase/ember-ui/addon/modifiers/set-container-dimensions.js

# Localise the published ember-ui navigation templates and their search labels.
COPY packages/ember-ui/addon/helpers/navigation-label.js /tmp/ember-ui-navigation/addon/helpers/navigation-label.js
COPY packages/ember-ui/app/helpers/navigation-label.js /tmp/ember-ui-navigation/app/helpers/navigation-label.js
COPY packages/ember-ui/addon/utils/translate-navigation-label.js /tmp/ember-ui-navigation/addon/utils/translate-navigation-label.js
COPY packages/ember-ui/addon/components/locale-selector-tray.js /tmp/ember-ui-navigation/addon/components/locale-selector-tray.js
COPY packages/ember-ui/addon/components/layout/mobile-navbar.hbs /tmp/ember-ui-navigation/addon/components/layout/mobile-navbar.hbs
COPY packages/ember-ui/addon/components/layout/header/smart-nav-menu.hbs /tmp/ember-ui-navigation/addon/components/layout/header/smart-nav-menu.hbs
COPY packages/ember-ui/addon/components/layout/header/smart-nav-menu/*.hbs /tmp/ember-ui-navigation/addon/components/layout/header/smart-nav-menu/
COPY packages/ember-ui/addon/components/layout/header/smart-nav-menu/dropdown.js /tmp/ember-ui-navigation/addon/components/layout/header/smart-nav-menu/dropdown.js
RUN cp -R /tmp/ember-ui-navigation/. node_modules/@fleetbase/ember-ui/

# Patch ember-leaflet icon helper: provide default iconUrl when none is set
COPY packages/ember-leaflet/addon/helpers/icon.js /tmp/ember-leaflet-icon.js
RUN cp /tmp/ember-leaflet-icon.js node_modules/ember-leaflet/addon/helpers/icon.js

# Copy the console directory contents
COPY console/ .

# Build the application
RUN echo "rebuild-$(date +%s)"
RUN pnpm build --environment $ENVIRONMENT || (cat /tmp/error.dump.*.log 2>/dev/null; exit 1)

# ---- Serve Stage ----
FROM nginx:alpine@sha256:54f2a904c251d5a34adf545a72d32515a15e08418dae0266e23be2e18c66fefa
RUN apk add --no-cache openssl && mkdir -p /etc/nginx/ssl
COPY docker/console-cert.sh /docker-entrypoint.d/10-ddmaps-cert.sh
RUN chmod +x /docker-entrypoint.d/10-ddmaps-cert.sh
COPY --from=builder /console/dist /usr/share/nginx/html
EXPOSE 4200
COPY console/nginx.conf /etc/nginx/conf.d/default.conf
CMD ["nginx", "-g", "daemon off;"]
