# msteams

Módulo FreePBX 17 para auxiliar integração MS Teams (PJSIP transports clonados com ms_signaling_address).

- Campo UI em Trunks PJSIP para FQDN do Teams
- Atualiza transporte do tronco para clone *-teams-*
- Gera pjsip.transports_custom.conf com ms_signaling_address

Requisitos: FreePBX 17, PJSIP habilitado.

Instalação:
1. Copie a pasta para admin/modules/msteams
2. fwconsole ma install msteams
3. fwconsole reload

Desinstalação:
- fwconsole ma uninstall msteams

Licença: AGPLv3
