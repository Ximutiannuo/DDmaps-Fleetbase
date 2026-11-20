import sys
content = open('/usr/share/nginx/html/engines-dist/@fleetbase/fleetops-engine/assets/engine-c0e29a617af7382475b28946dfb9f201.js').read()
idx = content.find('map-marker-bfdcdde')
if idx >= 0:
    print(content[max(0,idx-300):idx+300])
else:
    print('NOT FOUND')
