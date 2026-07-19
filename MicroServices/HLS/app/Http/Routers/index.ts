import { Router } from 'express';

import { apiToken } from '@/Http/Middleware/ApiToken';
import { packageQueue, PackageQueueService } from '@/Services/PackageQueueService';

import { HealthController } from '../Controllers/HealthController';
import { PackageController } from '../Controllers/PackageController';

export class Routers {
    private readonly router: Router = Router();
    private readonly healthController: HealthController;
    private readonly packageController: PackageController;

    public constructor(queue: PackageQueueService = packageQueue) {
        this.healthController = new HealthController(queue);
        this.packageController = new PackageController(queue);
        this.initializeRoutes();
    }

    public getRouter(): Router {
        return this.router;
    }

    private initializeRoutes(): void {
        this.router.get('/health', (req, res) => this.healthController.health(req, res));

        this.router.post('/package', apiToken, (req, res) =>
            this.packageController.create(req, res),
        );
    }
}
