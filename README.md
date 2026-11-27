# Painel Core

Plugin WordPress compartilhado distribuído como pacote Composer público. O código de desenvolvimento continua no repositório privado `rockhub2`; este repositório contém somente o plugin.

## Instalação via Composer

No `composer.json` do Bedrock, adicione o repositório VCS:

```json
{
  "type": "vcs",
  "url": "https://github.com/alexmfs/wp-painel-core"
}
```

Depois instale a versão estável:

```powershell
composer require alexmfs/painel-core:1.0.*
```

O pacote é público: a instalação não exige chave, login ou token. O Bedrock precisa permitir Packagist.org e ter o repositório WPackagist configurado para as dependências CMB2 e JWT Auth.