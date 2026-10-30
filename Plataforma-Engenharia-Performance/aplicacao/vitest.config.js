import { defineConfig } from 'vitest/config';

// Configuração mínima para rodar a suíte de forma estável neste ambiente
// (Windows + Node 24). O pool 'forks' com singleFork evita o erro
// "Vitest failed to find the runner" observado ao executar workers em paralelo
// via invocação direta do CLI. O app em si NÃO depende deste arquivo.
export default defineConfig({
    test: {
        environment: 'node',
        pool: 'forks',
        poolOptions: {
            forks: {
                singleFork: true
            }
        }
    }
});
