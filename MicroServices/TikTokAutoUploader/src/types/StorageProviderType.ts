export interface IStorageProvider {
    /** Baixa um único objeto pela chave e retorna o caminho local do arquivo. */
    downloadFile(key: string, destDir: string): Promise<string>;
}
