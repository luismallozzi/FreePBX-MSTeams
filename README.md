# msteams

Módulo FreePBX 17 para auxiliar integração MS Teams (PJSIP transports clonados com ms_signaling_address).

- Campo UI em Trunks PJSIP para FQDN do Teams
- Atualiza transporte do tronco para clone *-teams-*
- Gera pjsip.transports_custom.conf com ms_signaling_address

Requisitos: FreePBX 17, PJSIP habilitado, Asterisk com patch para `ms_signaling_address`.

Importante (patch necessário no Asterisk para MS Teams):
- É necessário aplicar um patch no Asterisk (chan_pjsip) que introduz a diretiva `ms_signaling_address` em transports PJSIP, utilizada pelo MS Teams. Sem esse patch, a linha gerada pelo módulo é ignorada pelo Asterisk e a integração não funciona.
- Após aplicar o patch, recompilar/instalar o Asterisk e reiniciar.

Instalação:
1. Copie a pasta para admin/modules/msteams
2. fwconsole ma install msteams
3. fwconsole reload

Desinstalação:
- fwconsole ma uninstall msteams

Licença: AGPLv3
