<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

# Vertigo Filmes
> Catálogo inteligente de filmes automatizado, resiliente e focado em performance.
[Acesse o projeto em produção](https://vertigo.click)

![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![Vue.js](https://img.shields.io/badge/Vue.js-35495E?style=for-the-badge&logo=vue.js&logoColor=4FC08D)
![Docker](https://img.shields.io/badge/Docker-2496ED?style=for-the-badge&logo=docker&logoColor=white)

## 📦 Como Rodar o Projeto

### Pré-requisitos
* Docker e Docker Compose instalados.

### Passo a Passo
1. Clone o repositório:
\`\`\`bash
git clone https://github.com/saymonp/backend-movie-app
\`\`\`
2. Suba os containers do ambiente:
\`\`\`bash
docker compose up -d
\`\`\`
3. Instale as dependências do ecossistema:
\`\`\`bash
docker compose exec app composer install
\`\`\`

## Executando os Testes
Para rodar a suíte de testes automatizados, execute:
\`\`\`bash
docker compose exec app ./vendor/bin/phpunit
\`\`\`


