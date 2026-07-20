import { Router } from 'express';

import { apiToken } from '@/Http/Middleware/ApiToken';
import { videoUpload } from '@/Http/Middleware/VideoUpload';
import { packageQueue, PackageQueueService } from '@/Services/PackageQueueService';
import { reencodeQueue, ReencodeQueueService } from '@/Services/ReencodeQueueService';

import { HealthController } from '../Controllers/HealthController';
import { PackageController } from '../Controllers/PackageController';
import { ReencodeController } from '../Controllers/ReencodeController';

export class Routers {
    private readonly router: Router = Router();
    private readonly healthController: HealthController;
    private readonly packageController: PackageController;
    private readonly reencodeController: ReencodeController;

    public constructor(
        queue: PackageQueueService = packageQueue,
        reencodes: ReencodeQueueService = reencodeQueue,
    ) {
        this.healthController = new HealthController(queue, reencodes);
        this.packageController = new PackageController(queue);
        this.reencodeController = new ReencodeController(reencodes);
        this.initializeRoutes();
    }

    public getRouter(): Router {
        return this.router;
    }

    private initializeRoutes(): void {
        this.router.get('/health', (req, res) => this.healthController.health(req, res));

        this.router.post('/package', apiToken.handle, (req, res) =>
            this.packageController.create(req, res),
        );

        this.router.post('/reencode', apiToken.handle, videoUpload.handle, (req, res, next) => {
            void this.reencodeController.create(req, res).catch(next);
        });
    }
}
