export class UploadProgress {
    private readonly bytesByPart = new Map<number, number>();

    constructor(
        private readonly totalBytes: number,
        private readonly partSize: number,
        private readonly report: (percent: number) => void,
    ) {}

    seed(partNumbers: number[]): void {
        for (const number of partNumbers) {
            this.bytesByPart.set(number, this.sizeOf(number));
        }
    }

    track(partNumber: number, loaded: number): void {
        this.bytesByPart.set(partNumber, loaded);
        this.emit();
    }

    settle(partNumber: number, size: number): void {
        this.bytesByPart.set(partNumber, size);
        this.emit();
    }

    drop(partNumber: number): void {
        this.bytesByPart.delete(partNumber);
    }

    emit(): void {
        let total = 0;

        for (const bytes of this.bytesByPart.values()) {
            total += bytes;
        }

        this.report(Math.min(99, Math.round((total / this.totalBytes) * 100)));
    }

    private sizeOf(partNumber: number): number {
        return Math.min(partNumber * this.partSize, this.totalBytes) - (partNumber - 1) * this.partSize;
    }
}
